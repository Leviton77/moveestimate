/**
 * Live-call transport shared by the client and rep pages.
 *
 * Wraps an RTCPeerConnection with the "perfect negotiation" pattern and a
 * WebSocket to the signaling Worker. SDP and ICE always travel over the
 * WebSocket (the Durable Object relays them); application messages (laser,
 * camera, contact form) prefer the peer connection's data channel and fall
 * back to the WebSocket before it opens.
 *
 * The rep is the impolite peer and owns the data channel; the client is polite.
 */

export type CallRole = "rep" | "client";

export type CallState =
  | "connecting"
  | "waiting"
  | "connected"
  | "reconnecting"
  | "failed"
  | "closed";

export type AppMessage =
  | { type: "laser"; from?: CallRole; x: number; y: number; active: boolean }
  | { type: "camera"; from?: CallRole; action: "flip" }
  | { type: "contact-form"; from?: CallRole; action: "open" }
  | { type: "contact-submitted"; from?: CallRole; [k: string]: unknown };

interface CallEvents {
  onRemoteStream?: (stream: MediaStream) => void;
  onAppMessage?: (msg: AppMessage) => void;
  onState?: (state: CallState) => void;
  onPeerPresence?: (present: boolean) => void;
}

export interface CallHandle {
  readonly pc: RTCPeerConnection;
  addLocalStream: (stream: MediaStream) => void;
  replaceVideoTrack: (track: MediaStreamTrack) => Promise<void>;
  send: (msg: AppMessage) => void;
  close: () => void;
}

const SIGNAL_TYPES = new Set(["description", "candidate", "bye"]);
const MAX_WS_RETRIES = 4;

export function startCall(opts: {
  callId: string;
  role: CallRole;
  signalingUrl: string;
  iceServers: RTCIceServer[];
  events: CallEvents;
}): CallHandle {
  const { callId, role, signalingUrl, iceServers, events } = opts;
  const polite = role === "client";

  const pc = new RTCPeerConnection({ iceServers });
  const remoteStream = new MediaStream();
  events.onRemoteStream?.(remoteStream);

  let ws: WebSocket | null = null;
  let wsRetries = 0;
  let closed = false;
  let peerPresent = false;
  let makingOffer = false;
  let ignoreOffer = false;
  let dataChannel: RTCDataChannel | null = null;
  const outbox: string[] = [];

  const setState = (s: CallState) => events.onState?.(s);

  // --- signaling transport -------------------------------------------------

  function wsSend(obj: unknown) {
    const line = JSON.stringify(obj);
    if (ws && ws.readyState === WebSocket.OPEN) ws.send(line);
    else outbox.push(line);
  }

  function connectWs() {
    if (closed) return;
    const base = signalingUrl.replace(/\/$/, "");
    ws = new WebSocket(`${base}/call/${callId}?role=${role}`);

    ws.onopen = () => {
      wsRetries = 0;
      setState(peerPresent ? "connected" : "waiting");
      while (outbox.length) ws!.send(outbox.shift()!);
    };

    ws.onmessage = (ev) => {
      let msg: Record<string, unknown>;
      try {
        msg = JSON.parse(String(ev.data));
      } catch {
        return;
      }
      routeIncoming(msg);
    };

    ws.onclose = () => {
      if (closed) return;
      if (wsRetries < MAX_WS_RETRIES) {
        wsRetries += 1;
        setState("reconnecting");
        setTimeout(connectWs, Math.min(1000 * 2 ** (wsRetries - 1), 8000));
      } else {
        setState("failed");
      }
    };

    ws.onerror = () => ws?.close();
  }

  // --- message routing ---------------------------------------------------

  function routeIncoming(msg: Record<string, unknown>) {
    const type = msg.type as string;

    if (type === "welcome") {
      const peers = (msg.peers as string[]) ?? [];
      if (peers.length) markPeerPresent();
      else setState("waiting");
      return;
    }
    if (type === "peer-joined") {
      markPeerPresent();
      return;
    }
    if (type === "peer-left") {
      peerPresent = false;
      events.onPeerPresence?.(false);
      setState("waiting");
      return;
    }
    if (type === "room-full") {
      setState("failed");
      return;
    }
    if (SIGNAL_TYPES.has(type)) {
      void handleSignal(msg);
      return;
    }
    // application message (also arrives here before the data channel is open)
    events.onAppMessage?.(msg as AppMessage);
  }

  function markPeerPresent() {
    if (peerPresent) return;
    peerPresent = true;
    events.onPeerPresence?.(true);
    setState("connected");
    if (!polite) void negotiate();
  }

  async function negotiate() {
    if (makingOffer || pc.signalingState !== "stable") return;
    try {
      makingOffer = true;
      await pc.setLocalDescription();
      wsSend({ type: "description", description: pc.localDescription });
    } catch {
      /* retried on next negotiationneeded */
    } finally {
      makingOffer = false;
    }
  }

  async function handleSignal(msg: Record<string, unknown>) {
    try {
      if (msg.type === "description") {
        const description = msg.description as RTCSessionDescriptionInit;
        const collision =
          description.type === "offer" &&
          (makingOffer || pc.signalingState !== "stable");
        ignoreOffer = !polite && collision;
        if (ignoreOffer) return;
        await pc.setRemoteDescription(description);
        if (description.type === "offer") {
          await pc.setLocalDescription();
          wsSend({ type: "description", description: pc.localDescription });
        }
      } else if (msg.type === "candidate") {
        try {
          await pc.addIceCandidate(msg.candidate as RTCIceCandidateInit);
        } catch (err) {
          if (!ignoreOffer) throw err;
        }
      } else if (msg.type === "bye") {
        // The peer ended the call deliberately. Surface it as a presence drop
        // (not just a state change) so the client can wrap up and upload the
        // recording the same way it does when the peer's socket simply drops.
        if (peerPresent) {
          peerPresent = false;
          events.onPeerPresence?.(false);
        }
        close();
      }
    } catch {
      /* ignore; perfect negotiation tolerates transient failures */
    }
  }

  // --- peer connection --------------------------------------------------

  pc.onnegotiationneeded = () => {
    if (peerPresent && !polite) void negotiate();
  };
  pc.onicecandidate = ({ candidate }) => {
    if (candidate) wsSend({ type: "candidate", candidate });
  };
  pc.ontrack = ({ track }) => {
    remoteStream.addTrack(track);
    events.onRemoteStream?.(remoteStream);
  };
  pc.onconnectionstatechange = () => {
    if (pc.connectionState === "failed") setState("failed");
    else if (pc.connectionState === "connected" && peerPresent) setState("connected");
  };
  pc.ondatachannel = ({ channel }) => bindDataChannel(channel);

  if (!polite) {
    bindDataChannel(pc.createDataChannel("app", { negotiated: false }));
  }

  function bindDataChannel(channel: RTCDataChannel) {
    dataChannel = channel;
    channel.onmessage = (ev) => {
      try {
        events.onAppMessage?.(JSON.parse(String(ev.data)) as AppMessage);
      } catch {
        /* drop malformed */
      }
    };
  }

  // --- handle ---------------------------------------------------------

  const handle: CallHandle = {
    pc,
    addLocalStream(stream) {
      for (const track of stream.getTracks()) pc.addTrack(track, stream);
    },
    async replaceVideoTrack(track) {
      const sender = pc.getSenders().find((s) => s.track?.kind === "video");
      if (sender) await sender.replaceTrack(track);
    },
    send(msg) {
      const line = JSON.stringify({ ...msg, from: role });
      if (dataChannel && dataChannel.readyState === "open") dataChannel.send(line);
      else wsSend({ ...msg, from: role });
    },
    close() {
      if (closed) return;
      closed = true;
      try {
        wsSend({ type: "bye" });
      } catch {
        /* best effort */
      }
      dataChannel?.close();
      ws?.close();
      pc.getSenders().forEach((s) => s.track?.stop());
      pc.close();
      setState("closed");
    },
  };

  setState("connecting");
  connectWs();
  return handle;
}
