"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { startCall, type AppMessage, type CallHandle, type CallState } from "./call";
import { drawLaser, LASER_COLOR, pointerToFrame, type LaserPoint } from "./laser";
import { HOME_SIZES, MESSAGES, type Locale } from "./messages";

interface VideoCallInterfaceProps {
  videoSessionId: string;
  repEmail: string;
  /** wss:// origin of the signaling Worker. Empty = record solo, no live call. */
  signalingUrl: string;
  /** Language the client sees, chosen by the rep when starting the call. */
  locale: Locale;
}

type Stage = "init" | "live" | "ending" | "done" | "error";

type ContactFields = {
  name: string;
  phone: string;
  email: string;
  note: string;
  moveDate: string;
  homeSize: string;
  currentAddress: string;
  destinationAddress: string;
};

/**
 * Pick a container/codec the current browser can actually record. Safari and
 * iOS do not support webm, so an unconditional "video/webm" mimeType throws in
 * the MediaRecorder constructor and the walkthrough never starts.
 */
function supportedMimeType() {
  if (
    typeof MediaRecorder === "undefined" ||
    typeof MediaRecorder.isTypeSupported !== "function"
  ) {
    return "";
  }
  return (
    [
      "video/mp4;codecs=h264,aac",
      "video/mp4",
      "video/webm;codecs=vp9,opus",
      "video/webm;codecs=vp8,opus",
      "video/webm",
    ].find((type) => MediaRecorder.isTypeSupported(type)) ?? ""
  );
}

export function VideoCallInterface({
  videoSessionId,
  repEmail,
  signalingUrl,
  locale,
}: VideoCallInterfaceProps) {
  const messages = MESSAGES[locale];
  const videoRef = useRef<HTMLVideoElement>(null);
  const remoteVideoRef = useRef<HTMLVideoElement>(null);
  const overlayRef = useRef<HTMLCanvasElement>(null);
  const recCanvasRef = useRef<HTMLCanvasElement>(null);
  const rafRef = useRef<number | null>(null);

  const mediaRecorderRef = useRef<MediaRecorder | null>(null);
  const cameraStreamRef = useRef<MediaStream | null>(null);
  const micStreamRef = useRef<MediaStream | null>(null);
  const canvasStreamRef = useRef<MediaStream | null>(null);
  const audioCtxRef = useRef<AudioContext | null>(null);
  const mixDestRef = useRef<MediaStreamAudioDestinationNode | null>(null);
  const repAudioMixedRef = useRef(false);
  // Progressive upload: recorded chunks not yet sent, the next piece number,
  // and byte counters for the "sending" progress. Sent chunks are dropped, so
  // the phone doesn't hold the whole call in memory either.
  const pendingRef = useRef<Blob[]>([]);
  const seqRef = useRef(0);
  const recordedBytesRef = useRef(0);
  const sentBytesRef = useRef(0);
  const inflightRef = useRef<Promise<void> | null>(null);
  const mimeTypeRef = useRef<string>("");
  const switchingRef = useRef(false);
  const finalizingRef = useRef(false);
  const callRef = useRef<CallHandle | null>(null);
  const lasersRef = useRef<{ rep: LaserPoint | null; client: LaserPoint | null }>({
    rep: null,
    client: null,
  });

  const [stage, setStage] = useState<Stage>("init");
  const [isFrontCamera, setIsFrontCamera] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [contactOpen, setContactOpen] = useState(false);
  const [contactSent, setContactSent] = useState(false);
  const [callState, setCallState] = useState<CallState | null>(null);
  const [repHere, setRepHere] = useState(false);
  const [repVideoReady, setRepVideoReady] = useState(false);
  const [sendPct, setSendPct] = useState(0);

  // Acquire a camera for the given facing mode, releasing the previous one
  // first (many phones refuse two open cameras). The canvas keeps painting the
  // last frame, so the recording is never interrupted.
  const applyCamera = useCallback(async (facingMode: "user" | "environment") => {
    const constraints = (mode: "user" | "environment"): MediaStreamConstraints => ({
      video: { facingMode: mode, width: { ideal: 1280 }, height: { ideal: 720 } },
    });

    cameraStreamRef.current?.getTracks().forEach((track) => track.stop());
    cameraStreamRef.current = null;

    let stream: MediaStream;
    try {
      stream = await navigator.mediaDevices.getUserMedia(constraints(facingMode));
    } catch (err) {
      try {
        const other = facingMode === "user" ? "environment" : "user";
        cameraStreamRef.current = await navigator.mediaDevices.getUserMedia(
          constraints(other),
        );
        if (videoRef.current) {
          videoRef.current.srcObject = cameraStreamRef.current;
          videoRef.current.play().catch(() => undefined);
        }
      } catch {
        /* no camera at all */
      }
      throw err;
    }

    cameraStreamRef.current = stream;
    const video = videoRef.current;
    if (video) {
      video.srcObject = stream;
      await video.play().catch(() => undefined);
    }
    const track = stream.getVideoTracks()[0];
    if (track) await callRef.current?.replaceVideoTrack(track);
  }, []);

  // Send everything recorded so far as the next numbered piece. One request
  // at a time: a call made while one is in flight just waits for it. On
  // failure the chunks stay pending and go out with the next attempt.
  const sendPending = useCallback((): Promise<void> => {
    if (inflightRef.current) return inflightRef.current;
    const batch = pendingRef.current.slice();
    if (batch.length === 0) return Promise.resolve();
    const type = mimeTypeRef.current || "video/webm";
    const blob = new Blob(batch, { type });
    const seq = seqRef.current;
    const request = (async () => {
      const res = await fetch(`/api/video-sessions/${videoSessionId}/parts?seq=${seq}`, {
        method: "POST",
        headers: { "content-type": type, "x-video-size": String(blob.size) },
        body: blob,
      });
      if (!res.ok) {
        const body = (await res.json().catch(() => ({}))) as { error?: string };
        throw new Error(body.error || messages.uploadFailedError);
      }
      pendingRef.current.splice(0, batch.length);
      seqRef.current = seq + 1;
      sentBytesRef.current += blob.size;
      if (recordedBytesRef.current > 0) {
        setSendPct(Math.min(100, Math.round((sentBytesRef.current / recordedBytesRef.current) * 100)));
      }
    })().finally(() => {
      inflightRef.current = null;
    });
    inflightRef.current = request;
    return request;
  }, [videoSessionId, messages]);

  const finalize = useCallback(
    async () => {
      if (finalizingRef.current) return;
      finalizingRef.current = true;
      setStage("ending");
      try {
        const recorder = mediaRecorderRef.current;
        if (recorder && recorder.state !== "inactive") {
          await new Promise<void>((resolve) => {
            recorder.addEventListener("stop", () => resolve(), { once: true });
            try {
              recorder.requestData();
            } catch {
              /* stop() flushes the tail */
            }
            recorder.stop();
          });
        }
        if (rafRef.current !== null) cancelAnimationFrame(rafRef.current);
        rafRef.current = null;
        [cameraStreamRef, micStreamRef, canvasStreamRef].forEach((ref) => {
          ref.current?.getTracks().forEach((t) => t.stop());
          ref.current = null;
        });
        void audioCtxRef.current?.close();
        audioCtxRef.current = null;

        if (recordedBytesRef.current === 0) {
          throw new Error(messages.nothingRecordedError);
        }
        // Most of the call is already on the server; send the rest, retrying
        // a few times through a flaky connection.
        for (let attempt = 0; ; attempt++) {
          try {
            if (inflightRef.current) await inflightRef.current.catch(() => undefined);
            while (pendingRef.current.length > 0) await sendPending();
            break;
          } catch (err) {
            if (attempt >= 3) throw err;
            await new Promise((resolve) => setTimeout(resolve, 1500 * (attempt + 1)));
          }
        }
        const res = await fetch(`/api/video-sessions/${videoSessionId}/complete`, {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: JSON.stringify({ parts: seqRef.current }),
        });
        if (!res.ok) {
          const body = (await res.json().catch(() => ({}))) as { error?: string };
          throw new Error(body.error || messages.uploadFailedError);
        }
        // Only now tell the rep the call is over. The rep's screen reacts to
        // this (a "bye" over the transport) by showing "Finish in Tom
        // Estimator" immediately — closing the transport any earlier let the
        // rep click through before the recording was actually uploaded,
        // which WordPress correctly (but confusingly) reported as "not ready
        // yet".
        callRef.current?.close();
        callRef.current = null;
        setStage("done");
      } catch (err) {
        finalizingRef.current = false;
        setError(err instanceof Error ? err.message : messages.couldNotUploadError);
        setStage("error");
      }
    },
    [videoSessionId, messages, sendPending],
  );

  const switchCamera = useCallback(async () => {
    if (switchingRef.current || stage !== "live") return;
    void audioCtxRef.current?.resume().catch(() => undefined);
    switchingRef.current = true;
    const next = !isFrontCamera;
    try {
      await applyCamera(next ? "user" : "environment");
      setIsFrontCamera(next);
      setError(null);
    } catch (err) {
      const detail = err instanceof Error && err.name ? ` (${err.name})` : "";
      setError(messages.switchCameraError(detail));
    } finally {
      switchingRef.current = false;
    }
  }, [applyCamera, isFrontCamera, stage, messages]);

  const onAppMessage = useCallback(
    (msg: AppMessage) => {
      if (msg.type === "laser" && msg.from === "rep") {
        lasersRef.current.rep = { x: msg.x, y: msg.y, active: msg.active, at: Date.now() };
      } else if (msg.type === "camera" && msg.action === "flip" && msg.from === "rep") {
        void switchCamera();
      } else if (msg.type === "contact-form" && msg.action === "open" && msg.from === "rep") {
        setContactOpen(true);
      } else if (msg.type === "end-call" && msg.from === "rep") {
        // The rep ended the call. Wrap up exactly as we do on a rep presence
        // drop: stop the recorder, upload, then send "bye" so the rep's
        // screen can settle. finalize() guards against running twice.
        void finalize();
      }
    },
    [switchCamera, finalize],
  );
  // startCall captures its callbacks once; route through a ref so incoming
  // messages always hit the current switchCamera (which reads live state).
  const onAppMessageRef = useRef(onAppMessage);
  useEffect(() => {
    onAppMessageRef.current = onAppMessage;
  }, [onAppMessage]);

  // --- one-time setup -------------------------------------------------------
  useEffect(() => {
    let cancelled = false;

    const teardown = () => {
      if (rafRef.current !== null) cancelAnimationFrame(rafRef.current);
      rafRef.current = null;
      const rec = mediaRecorderRef.current;
      if (rec && rec.state !== "inactive") {
        try {
          rec.stop();
        } catch {
          /* already stopping */
        }
      }
      mediaRecorderRef.current = null;
      callRef.current?.close();
      callRef.current = null;
      [cameraStreamRef, micStreamRef, canvasStreamRef].forEach((ref) => {
        ref.current?.getTracks().forEach((t) => t.stop());
        ref.current = null;
      });
      void audioCtxRef.current?.close();
      audioCtxRef.current = null;
    };

    const init = async () => {
      try {
        if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
          throw new Error(messages.cameraHttpsError);
        }

        micStreamRef.current = await navigator.mediaDevices.getUserMedia({
          audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
        });
        if (cancelled) return teardown();

        await applyCamera("user");
        if (cancelled) return teardown();

        const video = videoRef.current;
        const recCanvas = recCanvasRef.current;
        const overlay = overlayRef.current;
        if (!video || !recCanvas || !overlay) throw new Error(messages.recorderFailedError);

        await new Promise<void>((resolve) => {
          if (video.videoWidth > 0) return resolve();
          video.addEventListener("loadedmetadata", () => resolve(), { once: true });
        });
        if (cancelled) return teardown();

        // Fixed 720p recording surface. It must NOT be resized after the
        // captureStream() call below: Firefox and iOS Safari pin the recorded
        // track to the canvas size at capture time, so a later resize silently
        // crops the recording to a strip. Instead the current camera frame is
        // letterboxed (contain) into this fixed box every draw, so the whole
        // frame is always recorded whatever the camera aspect — or a mid-call
        // switch to a camera with a different one.
        const REC_W = 1280;
        const REC_H = 720;
        recCanvas.width = REC_W;
        recCanvas.height = REC_H;
        const rctx = recCanvas.getContext("2d");
        const octx = overlay.getContext("2d");

        const draw = () => {
          const src = videoRef.current;
          const lasers = lasersRef.current;
          if (rctx && src && src.readyState >= 2 && src.videoWidth > 0) {
            const vw = src.videoWidth;
            const vh = src.videoHeight;
            const scale = Math.min(REC_W / vw, REC_H / vh);
            const dw = vw * scale;
            const dh = vh * scale;
            const dx = (REC_W - dw) / 2;
            const dy = (REC_H - dh) / 2;
            rctx.fillStyle = "#000";
            rctx.fillRect(0, 0, REC_W, REC_H);
            rctx.drawImage(src, 0, 0, vw, vh, dx, dy, dw, dh);
            for (const role of ["rep", "client"] as const) {
              const pt = lasers[role];
              if (pt) drawLaser(rctx, REC_W, REC_H, vw, vh, pt, LASER_COLOR[role]);
            }
          }
          if (octx && src) {
            const rect = overlay.getBoundingClientRect();
            if (overlay.width !== rect.width || overlay.height !== rect.height) {
              overlay.width = rect.width;
              overlay.height = rect.height;
            }
            octx.clearRect(0, 0, overlay.width, overlay.height);
            const vw = src.videoWidth || overlay.width;
            const vh = src.videoHeight || overlay.height;
            for (const role of ["rep", "client"] as const) {
              const pt = lasers[role];
              if (pt) drawLaser(octx, overlay.width, overlay.height, vw, vh, pt, LASER_COLOR[role]);
            }
          }
          rafRef.current = requestAnimationFrame(draw);
        };
        draw();

        // audio: one mix destination; local mic in now, rep's voice added later
        const ac = new AudioContext();
        audioCtxRef.current = ac;
        void ac.resume().catch(() => undefined);
        const dest = ac.createMediaStreamDestination();
        mixDestRef.current = dest;
        ac.createMediaStreamSource(micStreamRef.current).connect(dest);

        const canvasStream = recCanvas.captureStream(30);
        canvasStreamRef.current = canvasStream;
        const recStream = new MediaStream([
          ...canvasStream.getVideoTracks(),
          ...dest.stream.getAudioTracks(),
        ]);
        const mimeType = supportedMimeType();
        mimeTypeRef.current = mimeType;
        // ~1.5 Mbps video keeps a 30-minute walkthrough around 330 MB (vs
        // ~18 MB/min at browser defaults) — plenty for a room walkthrough and
        // lighter on the customer's data while pieces upload during the call.
        // Browsers that can't honor a bitrate just ignore it.
        const recorder = new MediaRecorder(recStream, {
          ...(mimeType ? { mimeType } : {}),
          videoBitsPerSecond: 1_500_000,
          audioBitsPerSecond: 96_000,
        });
        mediaRecorderRef.current = recorder;
        recorder.ondataavailable = (e) => {
          if (e.data.size > 0) {
            pendingRef.current.push(e.data);
            recordedBytesRef.current += e.data.size;
          }
        };
        recorder.onerror = () => setError(messages.recordingStoppedError);
        recorder.start(1000);

        // live call (skipped when no signaling Worker is configured)
        if (signalingUrl) {
          const ice = await fetch("/api/turn")
            .then((r) => r.json() as Promise<{ iceServers: RTCIceServer[] }>)
            .catch(() => ({ iceServers: [{ urls: "stun:stun.cloudflare.com:3478" }] }));
          if (cancelled) return teardown();

          callRef.current = startCall({
            callId: videoSessionId,
            role: "client",
            signalingUrl,
            iceServers: ice.iceServers,
            events: {
              onState: setCallState,
              onPeerPresence: setRepHere,
              onAppMessage: (m) => onAppMessageRef.current(m),
              onRemoteStream: (stream) => {
                if (remoteVideoRef.current) {
                  remoteVideoRef.current.srcObject = stream;
                  remoteVideoRef.current.play().catch(() => undefined);
                }
                const rep = stream.getAudioTracks()[0];
                if (
                  rep &&
                  !repAudioMixedRef.current &&
                  audioCtxRef.current &&
                  mixDestRef.current
                ) {
                  repAudioMixedRef.current = true;
                  audioCtxRef.current
                    .createMediaStreamSource(new MediaStream([rep]))
                    .connect(mixDestRef.current);
                }
              },
            },
          });
          callRef.current.addLocalStream(
            new MediaStream([
              ...(cameraStreamRef.current?.getVideoTracks() ?? []),
              ...(micStreamRef.current?.getAudioTracks() ?? []),
            ]),
          );
        }

        setStage("live");
        setError(null);
      } catch (err) {
        if (cancelled) return;
        setError(err instanceof Error ? err.message : messages.couldNotStartCameraError);
        setStage("error");
        teardown();
      }
    };

    void init();
    return () => {
      cancelled = true;
      teardown();
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [signalingUrl, videoSessionId]);

  // The call ended from the other side — the rep left (presence drop) or the
  // call transport closed (rep hit "End call", socket gave up, …). Either way
  // the recording still has to be wrapped up and uploaded from the client.
  // finalize() guards against running twice.
  const prevRepHere = useRef(false);
  useEffect(() => {
    const repLeft = prevRepHere.current && !repHere;
    prevRepHere.current = repHere;
    if ((repLeft || callState === "closed") && stage === "live") {
      void finalize();
    }
  }, [repHere, callState, stage, finalize]);

  // Upload the recording as it happens, every few seconds, so ending the call
  // only has a short tail left to send — and a tab closed mid-upload still
  // leaves the server almost the whole call (assembled after a few minutes
  // of silence; see db/recording-parts.ts).
  useEffect(() => {
    if (stage !== "live") return;
    const timer = window.setInterval(() => {
      void sendPending().catch(() => undefined);
    }, 10_000);
    return () => window.clearInterval(timer);
  }, [stage, sendPending]);

  // Ask before leaving while there's still recording to send. Mobile
  // browsers often skip this prompt on tab close, which is why the upload
  // above doesn't wait for the end.
  useEffect(() => {
    if (stage !== "live" && stage !== "ending") return;
    const warn = (event: BeforeUnloadEvent) => {
      event.preventDefault();
      event.returnValue = "";
    };
    window.addEventListener("beforeunload", warn);
    return () => window.removeEventListener("beforeunload", warn);
  }, [stage]);

  // --- laser input --------------------------------------------------------
  const sendLaser = useCallback((clientX: number, clientY: number, active: boolean) => {
    // A pointer counts as the gesture some browsers need before audio flows.
    void audioCtxRef.current?.resume().catch(() => undefined);
    const video = videoRef.current;
    if (!video) return;
    const { x, y } = pointerToFrame(clientX, clientY, video);
    lasersRef.current.client = { x, y, active, at: Date.now() };
    callRef.current?.send({ type: "laser", x, y, active });
  }, []);

  const submitContact = useCallback(
    async (fields: ContactFields) => {
      const res = await fetch(`/api/video-sessions/${videoSessionId}/contact`, {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify(fields),
      });
      if (!res.ok) {
        const body = (await res.json().catch(() => ({}))) as { error?: string };
        throw new Error(body.error || messages.couldNotSendDetailsError);
      }
      callRef.current?.send({ type: "contact-submitted" });
      setContactSent(true);
      setContactOpen(false);
    },
    [videoSessionId, messages],
  );

  const stageBusy = stage === "ending";

  if (stage === "done") {
    return (
      <div className="video-call-container">
        <div className="done-card">
          <div className="done-mark" aria-hidden="true">✓</div>
          <h1>{messages.doneTitle}</h1>
          <p>{messages.doneBody(repEmail)}</p>
        </div>
      </div>
    );
  }

  if (stage === "error") {
    return (
      <div className="video-call-container">
        <div className="done-card">
          <div className="done-mark done-mark--warn" aria-hidden="true">!</div>
          <h1>{messages.errorTitle}</h1>
          <p>{error ?? messages.somethingWrong}</p>
          {/* After a failed send the recording is still in this page — retry
              the send. Reloading would throw it away. */}
          <button
            className="button button--primary"
            onClick={() =>
              recordedBytesRef.current > 0 ? void finalize() : window.location.reload()
            }
          >
            {messages.tryAgain}
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="video-call-container">
      <div className="video-call-header">
        <h1>{messages.pageTitle}</h1>
        <p className="call-status">
          {stage === "init"
            ? messages.startingCamera
            : !signalingUrl
              ? messages.recordingSolo
              : repHere
                ? messages.connectedWith(repEmail)
                : callState === "reconnecting"
                  ? messages.reconnecting
                  : callState === "failed"
                    ? messages.callFailedStillRecording
                    : messages.waitingForRep(repEmail)}
        </p>
      </div>

      <div className="video-call-content">
        <div className="video-stream">
          <video ref={videoRef} autoPlay playsInline muted className="video-element" />
          <canvas
            ref={overlayRef}
            className="laser-overlay"
            onPointerDown={(e) => {
              e.currentTarget.setPointerCapture(e.pointerId);
              sendLaser(e.clientX, e.clientY, true);
            }}
            onPointerMove={(e) => {
              if (e.buttons) sendLaser(e.clientX, e.clientY, true);
            }}
            onPointerUp={(e) => sendLaser(e.clientX, e.clientY, false)}
            onPointerCancel={(e) => sendLaser(e.clientX, e.clientY, false)}
          />
          <canvas ref={recCanvasRef} hidden />
          {signalingUrl && (
            <video
              ref={remoteVideoRef}
              autoPlay
              playsInline
              onLoadedMetadata={(e) => setRepVideoReady(e.currentTarget.videoWidth > 0)}
              onResize={(e) => setRepVideoReady(e.currentTarget.videoWidth > 0)}
              className={`remote-pip${repHere && repVideoReady ? "" : " remote-pip--empty"}`}
            />
          )}
          {stage === "init" && <div className="video-placeholder">{messages.startingCamera}</div>}
          {contactOpen && (
            <ContactSheet
              locale={locale}
              onSubmit={submitContact}
              onSkip={() => setContactOpen(false)}
            />
          )}
        </div>

        {error && <div className="error-message">{error}</div>}
        {contactSent && (
          <p className="call-status" style={{ color: "#7ee08a" }}>
            {messages.detailsSentTo(repEmail)}
          </p>
        )}

        <div className="video-controls">
          <button
            className="button button--secondary"
            onClick={switchCamera}
            disabled={stage !== "live" || stageBusy}
          >
            {isFrontCamera ? messages.showTheRoom : messages.showMyFace}
          </button>
          <button
            className="button button--primary"
            onClick={() => finalize()}
            disabled={stage !== "live" || stageBusy}
          >
            {stageBusy ? messages.sending : messages.endAndSend}
          </button>
        </div>

        <div className="video-info">
          <p className="recording-indicator">
            {stage === "live" && messages.recordingIndicator}
            {stageBusy && `${messages.sendingWalkthrough} ${sendPct}%`}
          </p>
          {stageBusy && <p className="session-info">{messages.keepPageOpen}</p>}
          <p className="session-info">{messages.dragToPoint}</p>
        </div>
      </div>
    </div>
  );
}

function ContactSheet({
  locale,
  onSubmit,
  onSkip,
}: {
  locale: Locale;
  onSubmit: (f: ContactFields) => Promise<void>;
  onSkip: () => void;
}) {
  const messages = MESSAGES[locale];
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const [email, setEmail] = useState("");
  const [moveDate, setMoveDate] = useState("");
  const [homeSize, setHomeSize] = useState("");
  const [currentAddress, setCurrentAddress] = useState("");
  const [destinationAddress, setDestinationAddress] = useState("");
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState("");

  return (
    <form
      className="contact-sheet"
      onSubmit={async (e) => {
        e.preventDefault();
        if (!name.trim()) {
          setErr(messages.nameRequiredError);
          return;
        }
        setBusy(true);
        setErr("");
        try {
          await onSubmit({
            name,
            phone,
            email,
            note,
            moveDate,
            homeSize,
            currentAddress,
            destinationAddress,
          });
        } catch (e2) {
          setErr(e2 instanceof Error ? e2.message : messages.couldNotSendError);
          setBusy(false);
        }
      }}
    >
      <h2>{messages.contactTitle}</h2>
      <p>{messages.contactIntro}</p>
      <input
        placeholder={messages.namePlaceholder}
        value={name}
        onChange={(e) => setName(e.target.value)}
        required
      />
      <input
        placeholder={messages.phonePlaceholder}
        inputMode="tel"
        value={phone}
        onChange={(e) => setPhone(e.target.value)}
      />
      <input
        placeholder={messages.emailPlaceholder}
        inputMode="email"
        value={email}
        onChange={(e) => setEmail(e.target.value)}
      />
      <label>
        <span>{messages.moveDateLabel}</span>
        <input type="date" value={moveDate} onChange={(e) => setMoveDate(e.target.value)} />
      </label>
      <label>
        <span>{messages.homeSizeLabel}</span>
        <select value={homeSize} onChange={(e) => setHomeSize(e.target.value)}>
          <option value="">{messages.homeSizeChoose}</option>
          {HOME_SIZES.map((size) => (
            <option key={size.value} value={size.value}>
              {size[locale]}
            </option>
          ))}
        </select>
      </label>
      <input
        placeholder={messages.currentAddressPlaceholder}
        value={currentAddress}
        onChange={(e) => setCurrentAddress(e.target.value)}
      />
      <input
        placeholder={messages.destinationAddressPlaceholder}
        value={destinationAddress}
        onChange={(e) => setDestinationAddress(e.target.value)}
      />
      <textarea
        placeholder={messages.notePlaceholder}
        rows={2}
        value={note}
        onChange={(e) => setNote(e.target.value)}
      />
      {err && <p className="contact-sheet__err">{err}</p>}
      <div className="contact-sheet__row">
        <button type="button" className="button button--secondary" onClick={onSkip} disabled={busy}>
          {messages.notNow}
        </button>
        <button type="submit" className="button button--primary" disabled={busy}>
          {busy ? messages.sending : messages.sendToRep}
        </button>
      </div>
    </form>
  );
}
