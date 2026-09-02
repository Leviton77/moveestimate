"use client";

import { useState } from "react";

interface VideoLinkGeneratorProps {
  repEmail: string;
}

export function VideoLinkGenerator({ repEmail }: VideoLinkGeneratorProps) {
  const [isOpen, setIsOpen] = useState(false);
  const [videoLink, setVideoLink] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const generateLink = async () => {
    try {
      setIsLoading(true);
      setError(null);

      const response = await fetch("/api/video-sessions", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ repEmail }),
      });

      if (!response.ok) {
        const data = await response.json();
        throw new Error(data.error || "Failed to generate link");
      }

      const data = await response.json();
      setVideoLink(data.joinLink);
    } catch (err) {
      const message = err instanceof Error ? err.message : "Failed to generate video link";
      setError(message);
    } finally {
      setIsLoading(false);
    }
  };

  const copyToClipboard = () => {
    if (videoLink) {
      navigator.clipboard.writeText(videoLink);
      alert("Video link copied to clipboard!");
    }
  };

  return (
    <>
      <button
        className="button button--secondary button--small"
        onClick={() => {
          setIsOpen(true);
          setVideoLink(null);
          setError(null);
        }}
      >
        📞 Generate Video Call Link
      </button>

      {isOpen && (
        <div className="modal-overlay" onClick={() => setIsOpen(false)}>
          <div className="modal-content" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <h2>Start Video Call Session</h2>
              <button
                className="modal-close"
                onClick={() => setIsOpen(false)}
              >
                ✕
              </button>
            </div>

            <div className="modal-body">
              {!videoLink ? (
                <>
                  <p>Generate a unique link to send to your customer for a live video walkthrough.</p>
                  <p className="info-text">
                    The customer will see their front camera by default and can switch to show their house.
                    The session will automatically record and submit the video for AI analysis.
                  </p>

                  {error && <div className="error-message">{error}</div>}

                  <button
                    className="button button--primary"
                    onClick={generateLink}
                    disabled={isLoading}
                  >
                    {isLoading ? "Generating..." : "Generate Link"}
                  </button>
                </>
              ) : (
                <>
                  <p className="success-message">✓ Video call link created successfully!</p>
                  <div className="link-container">
                    <input
                      type="text"
                      value={videoLink}
                      readOnly
                      className="link-input"
                    />
                    <button
                      className="button button--secondary"
                      onClick={copyToClipboard}
                    >
                      Copy Link
                    </button>
                  </div>
                  <p className="instruction-text">
                    Send this link to your customer. They can click it on their phone to start the video walkthrough.
                  </p>

                  <button
                    className="button button--secondary"
                    onClick={() => {
                      setVideoLink(null);
                      setIsOpen(false);
                    }}
                  >
                    Done
                  </button>
                </>
              )}
            </div>

            <style jsx>{`
              .modal-overlay {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.5);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 1000;
              }

              .modal-content {
                background: white;
                border-radius: 0.5rem;
                box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
                max-width: 500px;
                width: 90%;
                max-height: 80vh;
                overflow-y: auto;
              }

              .modal-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 1.5rem;
                border-bottom: 1px solid #e5e7eb;
              }

              .modal-header h2 {
                margin: 0;
                font-size: 1.25rem;
              }

              .modal-close {
                background: none;
                border: none;
                font-size: 1.5rem;
                cursor: pointer;
                padding: 0;
                line-height: 1;
              }

              .modal-body {
                padding: 1.5rem;
              }

              .modal-body p {
                margin: 0 0 1rem 0;
                color: #4b5563;
              }

              .info-text {
                font-size: 0.9rem;
                color: #6b7280;
              }

              .success-message {
                color: #10b981;
                font-weight: bold;
              }

              .error-message {
                background: #fee;
                color: #c33;
                padding: 0.75rem;
                border-radius: 0.25rem;
                margin-bottom: 1rem;
                font-size: 0.9rem;
              }

              .link-container {
                display: flex;
                gap: 0.5rem;
                margin-bottom: 1rem;
              }

              .link-input {
                flex: 1;
                padding: 0.75rem;
                border: 1px solid #d1d5db;
                border-radius: 0.25rem;
                font-family: monospace;
                font-size: 0.85rem;
              }

              .instruction-text {
                font-size: 0.85rem;
                color: #6b7280;
              }

              .button {
                padding: 0.75rem 1rem;
                border: none;
                border-radius: 0.25rem;
                font-size: 1rem;
                cursor: pointer;
                transition: all 0.3s;
              }

              .button--primary {
                background: #2563eb;
                color: white;
                width: 100%;
              }

              .button--primary:hover:not(:disabled) {
                background: #1d4ed8;
              }

              .button--secondary {
                background: #f3f4f6;
                color: #1f2937;
              }

              .button--secondary:hover:not(:disabled) {
                background: #e5e7eb;
              }

              .button:disabled {
                opacity: 0.5;
                cursor: not-allowed;
              }
            `}</style>
          </div>
        </div>
      )}
    </>
  );
}
