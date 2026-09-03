"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import { startCall, type AppMessage, type CallHandle, type CallState } from "../call";
import { drawLaser, LASER_COLOR, pointerToFrame, type LaserPoint } from "../laser";

interface RepCallProps {
  callId: string;
  repEmail: string;
  signalingUrl: string;
}

export function RepCall({ callId, repEmail, signalingUrl }: RepCallProps) {
  const selfVideoRef = useRef<HTMLVideoElement>(null);
  const clientVideoRef = useRef<HTMLVideoElement>(null);
  const overlayRef = useRef<HTMLCanvasElement>(null);
  const rafRef = useRef<number | null>(null);
  const localStreamRef = useRef<MediaStream | null>(null);
  const callRef = useRef<CallHandle | null>(null);
  const lasersRef = useRef<{ rep: LaserPoint | null; client: LaserPoint | null }>({
    rep: null,
    client: null,
  });

  const [state, setState] = useState<CallState | null>(null);
  const [clientHere, setClientHere] = useState(false);
  const [selfReady, setSelfReady] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [ended, setEnded] = useState(false);

  const onAppMessage = useCallback((msg: AppMessage) => {
    if (msg.type === "laser" && msg.from === "client") {
      lasersRef.current.client = { x: msg.x, y: msg.y, active: msg.active, at: Date.now() };
    }
  }, []);

  useEffect(() => {
    let cancelled = false;

    const teardown = () => {
      if (rafRef.current !== null) cancelAnimationFrame(rafRef.current);
      rafRef.current = null;
      callRef.current?.close();
      callRef.current = null;
      localStreamRef.current?.getTracks().forEach((t) => t.stop());
      localStreamRef.current = null;
    };

    const init = async () => {
      try {
        if (!signalingUrl) {
          throw new Error(
            "The live-call service isn't configured yet (SIGNALING_URL is unset).",
          );
        }
        if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
          throw new Error("Camera access needs HTTPS.");
        }

        const local = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: "user", width: { ideal: 1280 }, height: { ideal: 720 } },
          audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
        });
        if (cancelled) {
          local.getTracks().forEach((t) => t.stop());
          return;
        }
        localStreamRef.current = local;
        if (selfVideoRef.current) {
          selfVideoRef.current.srcObject = local;
          selfVideoRef.current.play().catch(() => undefined);
        }

        const ice = await fetch("/api/turn")
          .then((r) => r.json() as Promise<{ iceServers: RTCIceServer[] }>)
          .catch(() => ({ iceServers: [{ urls: "stun:stun.cloudflare.com:3478" }] }));
        if (cancelled) return teardown();

        callRef.current = startCall({
          callId,
          role: "rep",
          signalingUrl,
          iceServers: ice.iceServers,
          events: {
            onState: setState,
            onPeerPresence: setClientHere,
            onAppMessage,
            onRemoteStream: (stream) => {
              if (clientVideoRef.current) {
                clientVideoRef.current.srcObject = stream;
                clientVideoRef.current.play().catch(() => undefined);
              }
            },
          },
        });
        callRef.current.addLocalStream(local);

        const draw = () => {
          const video = clientVideoRef.current;
          const overlay = overlayRef.current;
          const octx = overlay?.getContext("2d");
          if (video && overlay && octx) {
            const rect = overlay.getBoundingClientRect();
            if (overlay.width !== rect.width || overlay.height !== rect.height) {
              overlay.width = rect.width;
              overlay.height = rect.height;
            }
            octx.clearRect(0, 0, overlay.width, overlay.height);
            const vw = video.videoWidth || overlay.width;
            const vh = video.videoHeight || overlay.height;
            for (const role of ["rep", "client"] as const) {
              const pt = lasersRef.current[role];
              if (pt) drawLaser(octx, overlay.width, overlay.height, vw, vh, pt, LASER_COLOR[role]);
            }
          }
          rafRef.current = requestAnimationFrame(draw);
        };
        draw();
        setError(null);
      } catch (err) {
        if (cancelled) return;
        setError(err instanceof Error ? err.message : "Couldn't start the call.");
        teardown();
      }
    };

    void init();
    return () => {
      cancelled = true;
      teardown();
    };
  }, [callId, signalingUrl, onAppMessage]);

  const sendLaser = useCallback((clientX: number, clientY: number, active: boolean) => {
    const video = clientVideoRef.current;
    if (!video || !video.videoWidth) return;
    const { x, y } = pointerToFrame(clientX, clientY, video);
    lasersRef.current.rep = { x, y, active, at: Date.now() };
    callRef.current?.send({ type: "laser", x, y, active });
  }, []);

  const flipTheirCamera = useCallback(() => {
    callRef.current?.send({ type: "camera", action: "flip" });
  }, []);

  const endCall = useCallback(() => {
    callRef.current?.close();
    if (rafRef.current !== null) cancelAnimationFrame(rafRef.current);
    localStreamRef.current?.getTracks().forEach((t) => t.stop());
    setEnded(true);
  }, []);

  if (ended) {
    return (
      <div className="video-call-container">
        <div className="done-card">
          <div className="done-mark" aria-hidden="true">✓</div>
          <h1>Call ended</h1>
          <p>
            The client&rsquo;s device is uploading the recording now. It will appear in your
            estimate requests shortly.
          </p>
          <Link className="button button--primary" href="/rep/dashboard">
            Back to dashboard
          </Link>
        </div>
      </div>
    );
  }

  return (
    <div className="video-call-container">
      <div className="video-call-header">
        <h1>Walkthrough call</h1>
        <p className="call-status">
          {error
            ? "Not connected"
            : clientHere
              ? "Client connected — drag on their video to point"
              : state === "reconnecting"
                ? "Reconnecting…"
                : state === "failed"
                  ? "Couldn't reach the call service — check SIGNALING_URL"
                  : "Waiting for the client to open the link…"}
        </p>
      </div>

      <div className="video-call-content">
        <div className="video-stream">
          <video ref={clientVideoRef} autoPlay playsInline className="video-element" />
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
          <video
            ref={selfVideoRef}
            autoPlay
            playsInline
            muted
            onLoadedMetadata={(e) => setSelfReady(e.currentTarget.videoWidth > 0)}
            onResize={(e) => setSelfReady(e.currentTarget.videoWidth > 0)}
            className={`remote-pip${selfReady ? "" : " remote-pip--empty"}`}
          />
          {!clientHere && !error && (
            <div className="video-placeholder">Waiting for the client…</div>
          )}
        </div>

        {error && <div className="error-message">{error}</div>}

        <div className="video-controls">
          <button
            className="button button--secondary"
            onClick={flipTheirCamera}
            disabled={!clientHere}
          >
            🔄 Flip their camera
          </button>
          <button className="button button--primary" onClick={endCall}>
            End call
          </button>
        </div>

        <div className="video-info">
          <p className="session-info">
            Signed in as {repEmail}. Your face is shown to the client but isn&rsquo;t recorded —
            only your voice and their walkthrough are.
          </p>
        </div>
      </div>
    </div>
  );
}
