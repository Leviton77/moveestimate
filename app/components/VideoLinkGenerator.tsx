"use client";

import { useState } from "react";

interface VideoLinkGeneratorProps {
  repEmail: string;
}

export function VideoLinkGenerator({ repEmail }: VideoLinkGeneratorProps) {
  const [isOpen, setIsOpen] = useState(false);
  const [videoLink, setVideoLink] = useState<string | null>(null);
  const [repPath, setRepPath] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [copied, setCopied] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const reset = () => {
    setVideoLink(null);
    setRepPath(null);
    setCopied(false);
    setError(null);
  };

  const generateLink = async () => {
    try {
      setIsLoading(true);
      setError(null);

      const response = await fetch("/api/video-sessions", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ repEmail }),
      });

      const data = (await response.json()) as {
        joinLink?: string;
        videoSessionId?: string;
        error?: string;
      };
      if (!response.ok) {
        throw new Error(data.error || "Failed to generate link");
      }
      if (!data.joinLink || !data.videoSessionId) {
        throw new Error("The server did not return a call link.");
      }
      setVideoLink(data.joinLink);
      setRepPath(`/video-call/${data.videoSessionId}/rep`);
    } catch (err) {
      const message = err instanceof Error ? err.message : "Failed to generate call link";
      setError(message);
    } finally {
      setIsLoading(false);
    }
  };

  const copyToClipboard = async () => {
    if (!videoLink) return;
    try {
      await navigator.clipboard.writeText(videoLink);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      setError("Couldn't copy — select the link and copy it manually.");
    }
  };

  return (
    <>
      <button
        type="button"
        className="button button--quiet button--small"
        onClick={() => {
          setIsOpen(true);
          reset();
        }}
      >
        📞 Start a live call
      </button>

      {isOpen && (
        <div className="video-link-modal" onClick={() => setIsOpen(false)}>
          <div className="modal-content" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <h2>Live walkthrough call</h2>
              <button
                type="button"
                className="modal-close"
                aria-label="Close"
                onClick={() => setIsOpen(false)}
              >
                ✕
              </button>
            </div>

            <div className="modal-body">
              {!videoLink ? (
                <>
                  <p>
                    Create a call, send the client link, then join it yourself. You&rsquo;ll be
                    on video together; the client&rsquo;s walkthrough and both voices are
                    recorded, your camera isn&rsquo;t.
                  </p>

                  {error && <div className="error-message">{error}</div>}

                  <button
                    type="button"
                    className="button button--primary"
                    onClick={generateLink}
                    disabled={isLoading}
                  >
                    {isLoading ? "Creating…" : "Create call"}
                  </button>
                </>
              ) : (
                <>
                  <p className="success-message">✓ Call created.</p>

                  <p className="instruction-text">1. Send this link to the client:</p>
                  <div className="link-container">
                    <input type="text" value={videoLink} readOnly className="link-input" />
                    <button
                      type="button"
                      className="button button--secondary"
                      onClick={copyToClipboard}
                    >
                      {copied ? "Copied" : "Copy"}
                    </button>
                  </div>

                  <p className="instruction-text">2. Then join the call:</p>
                  {repPath && (
                    <a className="button button--primary" href={repPath}>
                      Join the call
                    </a>
                  )}

                  {error && <div className="error-message">{error}</div>}

                  <button
                    type="button"
                    className="button button--secondary"
                    onClick={() => {
                      reset();
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
