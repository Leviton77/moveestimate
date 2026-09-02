"use client";

import { useEffect, useRef, useState } from "react";

interface VideoCallInterfaceProps {
  videoSessionId: string;
  repEmail: string;
}

export function VideoCallInterface({ videoSessionId, repEmail }: VideoCallInterfaceProps) {
  const videoRef = useRef<HTMLVideoElement>(null);
  const mediaRecorderRef = useRef<MediaRecorder | null>(null);
  const streamRef = useRef<MediaStream | null>(null);
  const chunksRef = useRef<Blob[]>([]);

  const [isConnected, setIsConnected] = useState(false);
  const [isFrontCamera, setIsFrontCamera] = useState(true);
  const [isRecording, setIsRecording] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [uploadProgress, setUploadProgress] = useState(0);
  const [isUploading, setIsUploading] = useState(false);

  // Initialize camera and start recording
  useEffect(() => {
    const initializeCall = async () => {
      try {
        // Request camera permission (front camera by default)
        const constraints = {
          video: {
            facingMode: isFrontCamera ? "user" : "environment",
            width: { ideal: 1280 },
            height: { ideal: 720 },
          },
          audio: {
            echoCancellation: true,
            noiseSuppression: true,
            autoGainControl: true,
          },
        };

        if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
          throw new Error(
            "Camera access requires HTTPS. Open this link over HTTPS or use localhost on the computer.",
          );
        }

        const stream = await navigator.mediaDevices.getUserMedia(constraints);
        streamRef.current = stream;

        if (videoRef.current) {
          videoRef.current.srcObject = stream;
          videoRef.current.play();
        }

        setIsConnected(true);

        // Start recording
        const mediaRecorder = new MediaRecorder(stream, {
          mimeType: "video/webm;codecs=vp8,opus",
        });

        mediaRecorderRef.current = mediaRecorder;
        chunksRef.current = [];

        mediaRecorder.ondataavailable = (event) => {
          if (event.data.size > 0) {
            chunksRef.current.push(event.data);
          }
        };

        mediaRecorder.onerror = (event) => {
          setError(`Recording error: ${event.error}`);
        };

        mediaRecorder.start();
        setIsRecording(true);
      } catch (err) {
        const message =
          err instanceof Error ? err.message : "Unable to access camera or microphone.";
        setError(message);
        setIsConnected(false);
      }
    };

    initializeCall();

    return () => {
      // Cleanup on unmount
      if (streamRef.current) {
        streamRef.current.getTracks().forEach((track) => track.stop());
      }
    };
  }, [isFrontCamera]);

  // Handle camera switch
  const switchCamera = async () => {
    try {
      // Stop current stream
      if (streamRef.current) {
        streamRef.current.getTracks().forEach((track) => track.stop());
      }

      // Stop recording before switching
      if (mediaRecorderRef.current && isRecording) {
        mediaRecorderRef.current.stop();
      }

      // Switch camera
      setIsFrontCamera(!isFrontCamera);
    } catch (err) {
      const message = err instanceof Error ? err.message : "Unable to switch camera.";
      setError(message);
    }
  };

  // Handle session end and upload
  const handleEndSession = async () => {
    try {
      setIsUploading(true);

      // Stop recording
      if (mediaRecorderRef.current && isRecording) {
        mediaRecorderRef.current.stop();
        setIsRecording(false);

        // Wait for the final ondataavailable event
        await new Promise((resolve) => {
          setTimeout(resolve, 100);
        });
      }

      // Stop all tracks
      if (streamRef.current) {
        streamRef.current.getTracks().forEach((track) => track.stop());
      }

      // Create blob from chunks
      if (chunksRef.current.length === 0) {
        throw new Error("No video data recorded.");
      }

      const videoBlob = new Blob(chunksRef.current, { type: "video/webm" });

      // Upload video
      const formData = new FormData();
      formData.append("video", videoBlob, `video-${Date.now()}.webm`);

      const uploadResponse = await fetch(
        `/api/video-sessions/${videoSessionId}/upload`,
        {
          method: "POST",
          body: formData,
        },
      );

      if (!uploadResponse.ok) {
        const errorData = await uploadResponse.json();
        throw new Error(errorData.error || "Video upload failed");
      }

      setUploadProgress(100);

      // Show success message and redirect
      setTimeout(() => {
        alert(
          "Your video has been successfully recorded and submitted for analysis. Thank you!",
        );
        window.location.href = "/";
      }, 2000);
    } catch (err) {
      const message = err instanceof Error ? err.message : "Unable to upload video.";
      setError(message);
      setIsUploading(false);
    }
  };

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
            {isUploading ? (
              <>Uploading... {uploadProgress}%</>
            ) : (
              <>End Session & Submit</>
            )}
          </button>
        </div>

        <div className="video-info">
          <p className="recording-indicator">
            {isRecording && "🔴 Recording..."}
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
