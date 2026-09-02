/**
 * Signaling Worker for the live estimate call.
 *
 * One Durable Object instance per call id acts as the "room": it relays the
 * WebRTC handshake (offer / answer / ICE) between the rep and the client and
 * tracks who is present. Once the peer connection's data channel is open the
 * browsers talk directly; this Worker is only needed for setup and reconnect.
 *
 *   wss://<worker>/call/<callId>?role=rep|client
 */

export { CallRoom } from "./room";

interface Env {
  CALL_ROOM: DurableObjectNamespace;
}

const CALL_ID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    const url = new URL(request.url);

    if (url.pathname === "/" || url.pathname === "/health") {
      return new Response("ok", { headers: { "content-type": "text/plain" } });
    }

    const match = url.pathname.match(/^\/call\/([^/]+)$/);
    if (!match) {
      return new Response("Not found", { status: 404 });
    }

    const callId = match[1].toLowerCase();
    if (!CALL_ID.test(callId)) {
      return new Response("Invalid call id", { status: 400 });
    }

    const role = url.searchParams.get("role");
    if (role !== "rep" && role !== "client") {
      return new Response("role must be 'rep' or 'client'", { status: 400 });
    }

    if (request.headers.get("Upgrade")?.toLowerCase() !== "websocket") {
      return new Response("Expected a WebSocket upgrade", { status: 426 });
    }

    const id = env.CALL_ROOM.idFromName(callId);
    return env.CALL_ROOM.get(id).fetch(request);
  },
};
