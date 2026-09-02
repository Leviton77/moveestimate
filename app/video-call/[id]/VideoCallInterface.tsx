"use client";

import { useCallback, useEffect, useRef, useState } from "react";

interface VideoCallInterfaceProps {
  videoSessionId: string;
  repEmail: string;
}

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

export function VideoCallInterface({ videoSessionId, repEmail }: VideoCallInterfaceProps) {
  const videoRef = useRef<HTMLVideoElement>(null);
  // Hidden canvas that the live camera is painted onto every frame. The
  // recorder captures the canvas, not the camera directly, so flipping the
  // camera only changes what is drawn — the recorded stream never breaks and
  // the whole call is one continuous file.
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const rafRef = useRef<number | null>(null);

  const mediaRecorderRef = useRef<MediaRecorder | null>(null);
  const cameraStreamRef = useRef<MediaStream | null>(null);
  const audioStreamRef = useRef<MediaStream | null>(null);
  const canvasStreamRef = useRef<MediaStream | null>(null);
  const chunksRef = useRef<Blob[]>([]);
  const mimeTypeRef = useRef<string>("");
  const switchingRef = useRef(false);

  const [isConnected, setIsConnected] = useState(false);
  const [isFrontCamera, setIsFrontCamera] = useState(true);
  const [isRecording, setIsRecording] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [isUploading, setIsUploading] = useState(false);

  // Point the preview <video> (and therefore the canvas draw loop) at a fresh
  // camera stream for the given facing mode, stopping the previous one.
  const applyCamera = useCallback(async (facingMode: "user" | "environment") => {
    const stream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode, width: { ideal: 1280 }, height: { ideal: 720 } },
    });
    cameraStreamRef.current?.getTracks().forEach((track) => track.stop());
    cameraStreamRef.current = stream;
    const video = videoRef.current;
    if (video) {
      video.srcObject = stream;
      await video.play().catch(() => undefined);
    }
  }, []);

  // One-time setup: acquire mic + camera, start the canvas draw loop, and
  // start a single recorder that runs for the entire call.
  useEffect(() => {
    let cancelled = false;

    const stopEverything = () => {
      if (rafRef.current !== null) cancelAnimationFrame(rafRef.current);
      rafRef.current = null;
      const recorder = mediaRecorderRef.current;
      if (recorder && recorder.state !== "inactive") {
        try {
          recorder.stop();
        } catch {
          // already stopping
        }
      }
      mediaRecorderRef.current = null;
      [cameraStreamRef, audioStreamRef, canvasStreamRef].forEach((ref) => {
        ref.current?.getTracks().forEach((track) => track.stop());
        ref.current = null;
      });
    };

    const initialize = async () => {
      try {
        if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
          throw new Error(
            "Camera access requires HTTPS. Open this link over HTTPS or use localhost on the computer.",
          );
        }

        audioStreamRef.current = await navigator.mediaDevices.getUserMedia({
          audio: {
            echoCancellation: true,
            noiseSuppression: true,
            autoGainControl: true,
          },
        });
        if (cancelled) return stopEverything();

        await applyCamera("user");
        if (cancelled) return stopEverything();

        const video = videoRef.current;
        const canvas = canvasRef.current;
        if (!video || !canvas) throw new Error("The recorder failed to start.");

        await new Promise<void>((resolve) => {
          if (video.videoWidth > 0) return resolve();
          video.addEventListener("loadedmetadata", () => resolve(), { once: true });
        });
        if (cancelled) return stopEverything();

        canvas.width = video.videoWidth || 720;
        canvas.height = video.videoHeight || 1280;
        const ctx = canvas.getContext("2d");

        const draw = () => {
          const source = videoRef.current;
          if (ctx && source && source.readyState >= 2) {
            const { width: cw, height: ch } = canvas;
            const vw = source.videoWidth || cw;
            const vh = source.videoHeight || ch;
            // cover-fit so a portrait/landscape camera swap never letterboxes
            const scale = Math.max(cw / vw, ch / vh);
            const dw = vw * scale;
            const dh = vh * scale;
            ctx.drawImage(source, (cw - dw) / 2, (ch - dh) / 2, dw, dh);
          }
          rafRef.current = requestAnimationFrame(draw);
        };
        draw();

        const canvasStream = canvas.captureStream(30);
        canvasStreamRef.current = canvasStream;
        const recordStream = new MediaStream([
          ...canvasStream.getVideoTracks(),
          ...(audioStreamRef.current?.getAudioTracks() ?? []),
        ]);

        const mimeType = supportedMimeType();
        mimeTypeRef.current = mimeType;
        const recorder = new MediaRecorder(
          recordStream,
          mimeType ? { mimeType } : undefined,
        );
        mediaRecorderRef.current = recorder;
        recorder.ondataavailable = (event) => {
          if (event.data.size > 0) chunksRef.current.push(event.data);
        };
        recorder.onerror = () => {
          setError("Recording stopped unexpectedly. Please end the session and try again.");
        };
        recorder.start(1000);

        setIsConnected(true);
        setIsRecording(true);
        setError(null);
      } catch (err) {
        if (cancelled) return;
        const message =
          err instanceof Error ? err.message : "Unable to access camera or microphone.";
        setError(message);
        setIsConnected(false);
        stopEverything();
      }
    };

    initialize();

    return () => {
      cancelled = true;
      stopEverything();
    };
  }, [applyCamera]);

  const switchCamera = useCallback(async () => {
    if (isUploading || switchingRef.current) return;
    switchingRef.current = true;
    const next = !isFrontCamera;
    try {
      await applyCamera(next ? "user" : "environment");
      setIsFrontCamera(next);
    } catch {
      setError("Unable to switch camera. The other camera may not be available.");
    } finally {
      switchingRef.current = false;
    }
  }, [applyCamera, isFrontCamera, isUploading]);

  const handleEndSession = useCallback(async () => {
    setIsUploading(true);
    try {
      const recorder = mediaRecorderRef.current;
      if (recorder && recorder.state !== "inactive") {
        await new Promise<void>((resolve) => {
          recorder.addEventListener("stop", () => resolve(), { once: true });
          try {
            recorder.requestData();
          } catch {
            // ignore; stop() flushes the final chunk
          }
          recorder.stop();
        });
      }
      setIsRecording(false);
      if (rafRef.current !== null) {
        cancelAnimationFrame(rafRef.current);
        rafRef.current = null;
      }
      [cameraStreamRef, audioStreamRef, canvasStreamRef].forEach((ref) => {
        ref.current?.getTracks().forEach((track) => track.stop());
        ref.current = null;
      });

      if (chunksRef.current.length === 0) {
        throw new Error("No video data was recorded.");
      }

      const type = mimeTypeRef.current || "video/webm";
      const extension = type.includes("mp4") ? "mp4" : "webm";
      const videoBlob = new Blob(chunksRef.current, { type });

      const formData = new FormData();
      formData.append("video", videoBlob, `walkthrough-${Date.now()}.${extension}`);

      const uploadResponse = await fetch(
        `/api/video-sessions/${videoSessionId}/upload`,
        { method: "POST", body: formData },
      );

      if (!uploadResponse.ok) {
        const errorData = (await uploadResponse
          .json()
          .catch(() => ({}))) as { error?: string };
        throw new Error(errorData.error || "Video upload failed.");
      }

      alert(
        "Your video has been successfully recorded and submitted for analysis. Thank you!",
      );
      window.location.href = "/";
    } catch (err) {
      const message = err instanceof Error ? err.message : "Unable to upload video.";
      setError(message);
      setIsUploading(false);
    }
  }, [videoSessionId]);

  return (
    <div className="video-call-container">
      <div className="video-call-header">
        <h1>Video Walkthrough</h1>
        <p>Connected with {repEmail}</p>
      </div>

      <div className="video-call-content">
        <div className="video-stream">
          <video
            ref={videoRef}
            autoPlay
            playsInline
            muted
            className="video-element"
          />
          <canvas ref={canvasRef} hidden />
          {!isConnected && <div className="video-placeholder">Connecting camera...</div>}
        </div>

        {error && <div className="error-message">{error}</div>}

        <div className="video-controls">
          <button
            className="button button--secondary"
            onClick={switchCamera}
            disabled={!isConnected || isUploading}
          >
            {isFrontCamera ? "📸 Switch to House View" : "📷 Switch to My Face"}
          </button>

          <button
            className="button button--primary"
            onClick={handleEndSession}
            disabled={!isConnected || isUploading}
          >
            {isUploading ? <>Uploading…</> : <>End Session &amp; Submit</>}
          </button>
        </div>

        <div className="video-info">
          <p className="recording-indicator">
            {isRecording && !isUploading && "🔴 Recording..."}
            {isUploading && "📤 Uploading video..."}
          </p>
          <p className="session-info">Your video is being recorded and will be analyzed for your estimate.</p>
        </div>
      </div>

      <style jsx>{`
        .video-call-container {
          width: 100%;
          height: 100vh;
          display: flex;
          flex-direction: column;
          background: #1a1a1a;
          color: #fff;
        }

        .video-call-header {
          padding: 1rem;
          background: rgba(0, 0, 0, 0.5);
          text-align: center;
          border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .video-call-header h1 {
          margin: 0;
          font-size: 1.5rem;
        }

        .video-call-header p {
          margin: 0.5rem 0 0 0;
          font-size: 0.9rem;
          opacity: 0.8;
        }

        .video-call-content {
          flex: 1;
          display: flex;
          flex-direction: column;
          padding: 1rem;
          gap: 1rem;
          overflow-y: auto;
        }

        .video-stream {
          flex: 1;
          display: flex;
          align-items: center;
          justify-content: center;
          background: #000;
          border-radius: 0.5rem;
          overflow: hidden;
          position: relative;
          min-height: 300px;
        }

        .video-element {
          width: 100%;
          height: 100%;
          object-fit: cover;
        }

        .video-placeholder {
          position: absolute;
          text-align: center;
          opacity: 0.5;
          pointer-events: none;
        }

        .error-message {
          background: #c33;
          color: white;
          padding: 1rem;
          border-radius: 0.5rem;
          font-size: 0.9rem;
        }

        .video-controls {
          display: flex;
          gap: 1rem;
          flex-wrap: wrap;
          justify-content: center;
        }

        .button {
          padding: 0.75rem 1.5rem;
          border: none;
          border-radius: 0.5rem;
          font-size: 1rem;
          font-weight: 500;
          cursor: pointer;
          transition: all 0.3s;
        }

        .button--primary {
          background: #2563eb;
          color: white;
        }

        .button--primary:hover:not(:disabled) {
          background: #1d4ed8;
        }

        .button--secondary {
          background: #475569;
          color: white;
        }

        .button--secondary:hover:not(:disabled) {
          background: #334155;
        }

        .button:disabled {
          opacity: 0.5;
          cursor: not-allowed;
        }

        .video-info {
          text-align: center;
          font-size: 0.9rem;
        }

        .recording-indicator {
          color: #ff4444;
          font-weight: bold;
          margin: 0;
          min-height: 1.2em;
        }

        .session-info {
          color: #aaa;
          margin: 0.5rem 0 0 0;
        }

        @media (max-width: 640px) {
          .video-call-container {
            height: 100dvh;
          }

          .video-call-header {
            padding: 0.75rem;
          }

          .video-call-header h1 {
            font-size: 1.2rem;
          }

          .video-controls {
            flex-direction: column;
          }

          .button {
            flex: 1;
            min-width: 100%;
          }
        }
      `}</style>
    </div>
  );
}
