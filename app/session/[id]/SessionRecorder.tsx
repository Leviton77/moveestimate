"use client";

import { useCallback, useEffect, useRef, useState } from "react";

type Stage = "loading" | "setup" | "preview" | "recording" | "review" | "uploading" | "done" | "missing";
type PublicSession = { id: string; clientName: string; status: string; videoUploaded: boolean };

const MAX_SECONDS = 15 * 60;
const MAX_BYTES = 250 * 1024 * 1024;

function supportedMimeType() {
  if (typeof MediaRecorder === "undefined" || typeof MediaRecorder.isTypeSupported !== "function") return "";
  return [
    "video/mp4;codecs=h264,aac",
    "video/mp4",
    "video/webm;codecs=vp9,opus",
    "video/webm;codecs=vp8,opus",
    "video/webm",
  ].find((type) => MediaRecorder.isTypeSupported(type)) ?? "";
}

function timeLabel(seconds: number) {
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, "0")}`;
}

export function SessionRecorder({ id }: { id: string }) {
  const previewRef = useRef<HTMLVideoElement>(null);
  const streamRef = useRef<MediaStream | null>(null);
  const recorderRef = useRef<MediaRecorder | null>(null);
  const chunksRef = useRef<Blob[]>([]);
  const clockRef = useRef<number | null>(null);
  const limitRef = useRef<number | null>(null);
  const [stage, setStage] = useState<Stage>("loading");
  const [session, setSession] = useState<PublicSession | null>(null);
  const [facingMode, setFacingMode] = useState<"environment" | "user">("environment");
  const [seconds, setSeconds] = useState(0);
  const [recording, setRecording] = useState<Blob | null>(null);
  const [recordingUrl, setRecordingUrl] = useState("");
  const [error, setError] = useState("");

  const stopStream = useCallback(() => {
    streamRef.current?.getTracks().forEach((track) => track.stop());
    streamRef.current = null;
  }, []);

  const clearTimers = useCallback(() => {
    if (clockRef.current) window.clearInterval(clockRef.current);
    if (limitRef.current) window.clearTimeout(limitRef.current);
    clockRef.current = null;
    limitRef.current = null;
  }, []);

  const stopRecording = useCallback(() => {
    const recorder = recorderRef.current;
    if (recorder?.state === "recording") recorder.stop();
    clearTimers();
    stopStream();
  }, [clearTimers, stopStream]);

  useEffect(() => {
    let active = true;
    fetch(`/api/sessions/${id}`, { cache: "no-store" })
      .then(async (response) => {
        const body = (await response.json()) as { session?: PublicSession; error?: string };
        if (!response.ok || !body.session) throw new Error(body.error ?? "Session not found.");
        if (!active) return;
        setSession(body.session);
        setStage(body.session.videoUploaded ? "done" : "setup");
      })
      .catch(() => active && setStage("missing"));
    return () => { active = false; };
  }, [id]);

  useEffect(() => () => { stopRecording(); if (recordingUrl) URL.revokeObjectURL(recordingUrl); }, [recordingUrl, stopRecording]);

  async function enableCamera(nextFacing = facingMode) {
    setError("");
    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === "undefined") {
      setError("This browser cannot record video. Please open this link in the current Safari, Chrome, or Edge browser on your phone.");
      return;
    }
    try {
      stopStream();
      const stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: { ideal: nextFacing }, width: { ideal: 1280 }, height: { ideal: 720 } },
        audio: true,
      });
      streamRef.current = stream;
      if (previewRef.current) previewRef.current.srcObject = stream;
      setStage("preview");
    } catch {
      setError("Camera or microphone access was blocked. Allow access in your browser settings, then try again.");
    }
  }

  async function switchCamera() {
    const next = facingMode === "environment" ? "user" : "environment";
    setFacingMode(next);
    await enableCamera(next);
  }

  function beginRecording() {
    if (!streamRef.current) return;
    setError("");
    setRecording(null);
    if (recordingUrl) URL.revokeObjectURL(recordingUrl);
    setRecordingUrl("");
    chunksRef.current = [];
    try {
      const mimeType = supportedMimeType();
      const recorder = mimeType ? new MediaRecorder(streamRef.current, { mimeType }) : new MediaRecorder(streamRef.current);
      recorderRef.current = recorder;
      recorder.ondataavailable = (event) => { if (event.data.size) chunksRef.current.push(event.data); };
      recorder.onstop = () => {
        const blob = new Blob(chunksRef.current, { type: recorder.mimeType || mimeType || "video/webm" });
        if (!blob.size) { setError("No video was captured. Please try again."); setStage("setup"); return; }
        const url = URL.createObjectURL(blob);
        setRecording(blob);
        setRecordingUrl(url);
        setStage("review");
      };
      recorder.onerror = () => { setError("Recording stopped unexpectedly. Please try again."); setStage("setup"); };
      recorder.start(1000);
      setSeconds(0);
      setStage("recording");
      clockRef.current = window.setInterval(() => setSeconds((value) => value + 1), 1000);
      limitRef.current = window.setTimeout(stopRecording, MAX_SECONDS * 1000);
    } catch {
      setError("This browser could not start the recorder. Try the current Safari, Chrome, or Edge browser.");
    }
  }

  async function retake() {
    if (recordingUrl) URL.revokeObjectURL(recordingUrl);
    setRecordingUrl(""); setRecording(null); setSeconds(0);
    await enableCamera();
  }

  async function upload() {
    if (!recording) return;
    if (recording.size > MAX_BYTES) {
      setError("This recording is larger than 250 MB. Please retake a shorter walkthrough.");
      return;
    }
    setStage("uploading"); setError("");
    try {
      const contentType = recording.type.split(";")[0] || "video/webm";
      const response = await fetch(`/api/sessions/${id}/video`, {
        method: "POST",
        headers: { "content-type": contentType, "x-video-size": String(recording.size) },
        body: recording,
      });
      const body = (await response.json()) as { error?: string };
      if (!response.ok) throw new Error(body.error ?? "Upload failed.");
      if (recordingUrl) URL.revokeObjectURL(recordingUrl);
      setRecordingUrl(""); setRecording(null); setStage("done");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Upload failed. Please try again.");
      setStage("review");
    }
  }

  if (stage === "loading") return <div className="status-card"><div className="loader" /><p>Loading your secure session…</p></div>;
  if (stage === "missing") return <div className="status-card"><span className="status-icon">?</span><h1>Session not found</h1><p>This link may be invalid or expired. Start a new estimate if you still need a quote.</p><a className="button button--primary" href="/estimate">Start an estimate</a></div>;
  if (stage === "done") return <div className="status-card"><span className="status-icon status-icon--success">✓</span><h1>Thank you{session ? `, ${session.clientName}` : ""}!</h1><p>Your walkthrough is safely uploaded. Tom Moving will review it and follow up within one business day.</p><a className="button button--quiet" href="/">Return home</a></div>;

  return (
    <div className="recorder-shell">
      <div className="page-intro page-intro--left"><p className="eyebrow">Step 2 of 2</p><h1>Video walkthrough</h1><p>Hi {session?.clientName}. Show each room slowly, plus stairs, large furniture, and special items.</p></div>
      {error && <p className="form-error" role="alert">{error}</p>}
      {stage === "setup" ? (
        <div className="permission-card"><div className="permission-card__mark">●</div><h2>Ready to record?</h2><p>Your browser will ask for camera and microphone access. Nothing is uploaded until you review the video and press Submit.</p><button className="button button--primary" onClick={() => enableCamera()}>Enable camera</button></div>
      ) : (
        <div className="recorder-card">
          <div className="video-stage">
            {stage === "review" && recordingUrl ? <video src={recordingUrl} controls playsInline /> : <video ref={previewRef} autoPlay muted playsInline />}
            {stage === "recording" && <div className="recording-pill"><span />REC {timeLabel(seconds)}</div>}
            {stage === "preview" && <button className="camera-switch" type="button" onClick={switchCamera}>Switch camera</button>}
          </div>
          <div className="recorder-controls">
            {stage === "preview" && <><p>Hold your phone horizontally when possible, then walk at a comfortable pace.</p><button className="button button--record" onClick={beginRecording}>Start recording</button></>}
            {stage === "recording" && <><p>Recording {timeLabel(seconds)} · maximum 15:00</p><button className="button button--stop" onClick={stopRecording}>Stop recording</button></>}
            {stage === "review" && <><p>Review your walkthrough before sending it.</p><div className="button-row button-row--center"><button className="button button--quiet" onClick={retake}>Retake</button><button className="button button--primary" onClick={upload}>Submit video</button></div></>}
            {stage === "uploading" && <div className="uploading"><div className="loader" /><p>Securely uploading your walkthrough. Keep this page open…</p></div>}
          </div>
        </div>
      )}
      <p className="privacy-note">Your recording is private and used only to prepare your moving estimate.</p>
    </div>
  );
}
