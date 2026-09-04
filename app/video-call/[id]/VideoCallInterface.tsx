"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { startCall, type AppMessage, type CallHandle, type CallState } from "./call";
import { drawLaser, LASER_COLOR, pointerToFrame, type LaserPoint } from "./laser";

interface VideoCallInterfaceProps {
  videoSessionId: string;
  repEmail: string;
  /** wss:// origin of the signaling Worker. Empty = record solo, no live call. */
  signalingUrl: string;
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

const HOME_SIZES = [
  "Studio",
  "1 bedroom",
  "2 bedrooms",
  "3 bedrooms",
  "4+ bedrooms",
  "House",
  "Storage unit",
];

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
}: VideoCallInterfaceProps) {
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
  const chunksRef = useRef<Blob[]>([]);
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

        if (chunksRef.current.length === 0) {
          throw new Error("Nothing was recorded.");
        }
        const type = mimeTypeRef.current || "video/webm";
        const blob = new Blob(chunksRef.current, { type });
        const res = await fetch(`/api/video-sessions/${videoSessionId}/upload`, {
          method: "POST",
          headers: { "content-type": type, "x-video-size": String(blob.size) },
          body: blob,
        });
        if (!res.ok) {
          const body = (await res.json().catch(() => ({}))) as { error?: string };
          throw new Error(body.error || "Upload failed.");
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
        setError(err instanceof Error ? err.message : "Could not upload the recording.");
        setStage("error");
      }
    },
    [videoSessionId],
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
      setError(`Couldn't switch camera${detail}. Staying on the current one.`);
    } finally {
      switchingRef.current = false;
    }
  }, [applyCamera, isFrontCamera, stage]);

  const onAppMessage = useCallback(
    (msg: AppMessage) => {
      if (msg.type === "laser" && msg.from === "rep") {
        lasersRef.current.rep = { x: msg.x, y: msg.y, active: msg.active, at: Date.now() };
      } else if (msg.type === "camera" && msg.action === "flip" && msg.from === "rep") {
        void switchCamera();
      } else if (msg.type === "contact-form" && msg.action === "open" && msg.from === "rep") {
        setContactOpen(true);
      }
    },
    [switchCamera],
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
          throw new Error(
            "Camera access needs HTTPS. Open this link over HTTPS or use localhost on a computer.",
          );
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
        if (!video || !recCanvas || !overlay) throw new Error("The recorder failed to start.");

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
        const recorder = new MediaRecorder(recStream, mimeType ? { mimeType } : undefined);
        mediaRecorderRef.current = recorder;
        recorder.ondataavailable = (e) => {
          if (e.data.size > 0) chunksRef.current.push(e.data);
        };
        recorder.onerror = () =>
          setError("Recording stopped unexpectedly. Please end the call and try again.");
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
        setError(
          err instanceof Error ? err.message : "Couldn't start the camera or microphone.",
        );
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
        throw new Error(body.error || "Couldn't send your details.");
      }
      callRef.current?.send({ type: "contact-submitted" });
      setContactSent(true);
      setContactOpen(false);
    },
    [videoSessionId],
  );

  const stageBusy = stage === "ending";

  if (stage === "done") {
    return (
      <div className="video-call-container">
        <div className="done-card">
          <div className="done-mark" aria-hidden="true">✓</div>
          <h1>Thanks — your walkthrough is in.</h1>
          <p>{repEmail} has your recording and will follow up with an estimate. You can close this page.</p>
        </div>
      </div>
    );
  }

  if (stage === "error") {
    return (
      <div className="video-call-container">
        <div className="done-card">
          <div className="done-mark done-mark--warn" aria-hidden="true">!</div>
          <h1>The call couldn&rsquo;t continue</h1>
          <p>{error ?? "Something went wrong."}</p>
          <button className="button button--primary" onClick={() => window.location.reload()}>
            Try again
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="video-call-container">
      <div className="video-call-header">
        <h1>Live walkthrough</h1>
        <p className="call-status">
          {stage === "init"
            ? "Starting your camera…"
            : !signalingUrl
              ? "Recording your walkthrough"
              : repHere
                ? `Connected with ${repEmail}`
                : callState === "reconnecting"
                  ? "Reconnecting…"
                  : callState === "failed"
                    ? "Couldn't reach the call — still recording your walkthrough"
                    : `Waiting for ${repEmail} to join…`}
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
          {stage === "init" && <div className="video-placeholder">Starting camera…</div>}
          {contactOpen && (
            <ContactSheet
              onSubmit={submitContact}
              onSkip={() => setContactOpen(false)}
            />
          )}
        </div>

        {error && <div className="error-message">{error}</div>}
        {contactSent && (
          <p className="call-status" style={{ color: "#7ee08a" }}>
            ✓ Your details were sent to {repEmail}.
          </p>
        )}

        <div className="video-controls">
          <button
            className="button button--secondary"
            onClick={switchCamera}
            disabled={stage !== "live" || stageBusy}
          >
            {isFrontCamera ? "📸 Show the room" : "🙂 Show my face"}
          </button>
          <button
            className="button button--primary"
            onClick={() => finalize()}
            disabled={stage !== "live" || stageBusy}
          >
            {stageBusy ? "Sending…" : "End & send"}
          </button>
        </div>

        <div className="video-info">
          <p className="recording-indicator">
            {stage === "live" && "🔴 Recording"}
            {stageBusy && "📤 Sending your walkthrough…"}
          </p>
          <p className="session-info">
            Drag on the video to point. This call is recorded for your estimate.
          </p>
        </div>
      </div>
    </div>
  );
}

function ContactSheet({
  onSubmit,
  onSkip,
}: {
  onSubmit: (f: ContactFields) => Promise<void>;
  onSkip: () => void;
}) {
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
          setErr("Please add your name.");
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
          setErr(e2 instanceof Error ? e2.message : "Couldn't send.");
          setBusy(false);
        }
      }}
    >
      <h2>Your contact details</h2>
      <p>Your rep asked for these so they can follow up with your estimate. Only your name is required.</p>
      <input
        placeholder="Name *"
        value={name}
        onChange={(e) => setName(e.target.value)}
        required
      />
      <input
        placeholder="Phone (optional)"
        inputMode="tel"
        value={phone}
        onChange={(e) => setPhone(e.target.value)}
      />
      <input
        placeholder="Email (optional)"
        inputMode="email"
        value={email}
        onChange={(e) => setEmail(e.target.value)}
      />
      <label>
        <span>Expected moving date (optional)</span>
        <input type="date" value={moveDate} onChange={(e) => setMoveDate(e.target.value)} />
      </label>
      <label>
        <span>Home size (optional)</span>
        <select value={homeSize} onChange={(e) => setHomeSize(e.target.value)}>
          <option value="">Choose one…</option>
          {HOME_SIZES.map((size) => (
            <option key={size} value={size}>
              {size}
            </option>
          ))}
        </select>
      </label>
      <input
        placeholder="Current address (optional)"
        value={currentAddress}
        onChange={(e) => setCurrentAddress(e.target.value)}
      />
      <input
        placeholder="Destination address (optional)"
        value={destinationAddress}
        onChange={(e) => setDestinationAddress(e.target.value)}
      />
      <textarea
        placeholder="Anything else (optional)"
        rows={2}
        value={note}
        onChange={(e) => setNote(e.target.value)}
      />
      {err && <p className="contact-sheet__err">{err}</p>}
      <div className="contact-sheet__row">
        <button type="button" className="button button--secondary" onClick={onSkip} disabled={busy}>
          Not now
        </button>
        <button type="submit" className="button button--primary" disabled={busy}>
          {busy ? "Sending…" : "Send to rep"}
        </button>
      </div>
    </form>
  );
}
