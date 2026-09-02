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

      const data = (await response.json()) as { joinLink?: string; error?: string };
      if (!response.ok) {
        throw new Error(data.error || "Failed to generate link");
      }
      if (!data.joinLink) {
        throw new Error("The server did not return a video link.");
      }
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
        type="button"
        className="button button--quiet button--small"
        onClick={() => {
          setIsOpen(true);
          setVideoLink(null);
          setError(null);
        }}
      >
        📞 Generate Video Call Link
      </button>

      {isOpen && (
        <div className="video-link-modal" onClick={() => setIsOpen(false)}>
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
          </div>
        </div>
      )}
    </>
  );
}
