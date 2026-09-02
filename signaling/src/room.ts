/**
 * CallRoom — one Durable Object instance per call id.
 *
 * Holds at most two live WebSockets, tagged "rep" and "client". Every message
 * from one peer is forwarded verbatim to the other; the DO does not parse or
 * store call content. Uses the WebSocket Hibernation API so an idle room costs
 * nothing and survives eviction.
 *
 * Messages the browsers exchange (opaque to this DO, listed for reference):
 *   offer / answer / ice   — WebRTC negotiation
 *   bye                    — graceful hangup
 *   laser / camera / contact-form / contact-submitted
 *                          — normally sent peer-to-peer once the RTCDataChannel
 *                            is open; relayed here too as a pre-connection path
 *
 * Messages this DO injects:
 *   { type: "welcome", role, peers }   on connect, to the joiner
 *   { type: "peer-joined", role }      to the other peer
 *   { type: "peer-left", role }        to the other peer on close/error
 *   { type: "room-full" }              then closes, if that role is already taken
 */

type Role = "rep" | "client";

export class CallRoom {
  private readonly state: DurableObjectState;

  constructor(state: DurableObjectState) {
    this.state = state;
  }

  async fetch(request: Request): Promise<Response> {
    const role = new URL(request.url).searchParams.get("role") as Role;

    if (this.socketFor(role)) {
      // A socket with this role is already connected. Reject the newcomer
      // rather than silently bumping the incumbent mid-call.
      const [reject, mine] = Object.values(new WebSocketPair());
      mine.accept();
      mine.send(JSON.stringify({ type: "room-full" }));
      mine.close(1013, "role already connected");
      return new Response(null, { status: 101, webSocket: reject });
    }

    const pair = new WebSocketPair();
    const client = pair[0];
    const server = pair[1];

    this.state.acceptWebSocket(server, [role]);

    const peerRole: Role = role === "rep" ? "client" : "rep";
    server.send(
      JSON.stringify({
        type: "welcome",
        role,
        peers: this.socketFor(peerRole) ? [peerRole] : [],
      }),
    );
    this.sendTo(peerRole, { type: "peer-joined", role });

    return new Response(null, { status: 101, webSocket: client });
  }

  webSocketMessage(ws: WebSocket, message: string | ArrayBuffer): void {
    if (typeof message !== "string") return;
    const from = this.roleOf(ws);
    if (!from) return;
    const to: Role = from === "rep" ? "client" : "rep";
    // Forward opaque. Tag with the sender so the receiver can attribute it.
    let payload = message;
    try {
      const parsed = JSON.parse(message) as Record<string, unknown>;
      parsed.from = from;
      payload = JSON.stringify(parsed);
    } catch {
      // not JSON — forward as-is
    }
    this.sendRawTo(to, payload);
  }

  webSocketClose(ws: WebSocket): void {
    this.announceLeave(ws);
  }

  webSocketError(ws: WebSocket): void {
    this.announceLeave(ws);
  }

  private announceLeave(ws: WebSocket): void {
    const role = this.roleOf(ws);
    if (!role) return;
    const peer: Role = role === "rep" ? "client" : "rep";
    this.sendTo(peer, { type: "peer-left", role });
  }

  private roleOf(ws: WebSocket): Role | null {
    const tags = this.state.getTags(ws);
    if (tags.includes("rep")) return "rep";
    if (tags.includes("client")) return "client";
    return null;
  }

  private socketFor(role: Role): WebSocket | undefined {
    return this.state.getWebSockets(role)[0];
  }

  private sendTo(role: Role, obj: unknown): void {
    this.sendRawTo(role, JSON.stringify(obj));
  }

  private sendRawTo(role: Role, data: string): void {
    const target = this.socketFor(role);
    if (!target) return;
    try {
      target.send(data);
    } catch {
      // peer is gone; its close handler will clean up
    }
  }
}
