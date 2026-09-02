/**
 * Laser-pointer coordinate mapping and drawing, shared by both call pages.
 *
 * Coordinates on the wire are normalized to the client's camera frame (0–1),
 * so the rep points at a spot on the frame and the client — and the recording —
 * render it in the same place regardless of each screen's size or aspect.
 */

export type LaserPoint = { x: number; y: number; active: boolean; at: number };

export const LASER_COLOR: Record<"rep" | "client", string> = {
  rep: "#ff3b30",
  client: "#00d0e0",
};

const TRAIL_MS = 2500;

/**
 * Map a pointer event over an `object-fit: cover` <video> to normalized
 * coordinates on the source frame. Clamped to 0–1.
 */
export function pointerToFrame(
  clientX: number,
  clientY: number,
  video: HTMLVideoElement,
): { x: number; y: number } {
  const rect = video.getBoundingClientRect();
  const vw = video.videoWidth || rect.width;
  const vh = video.videoHeight || rect.height;
  const scale = Math.max(rect.width / vw, rect.height / vh);
  const dw = vw * scale;
  const dh = vh * scale;
  const offX = (rect.width - dw) / 2;
  const offY = (rect.height - dh) / 2;
  const sx = (clientX - rect.left - offX) / scale;
  const sy = (clientY - rect.top - offY) / scale;
  return {
    x: clamp01(sx / vw),
    y: clamp01(sy / vh),
  };
}

/**
 * Draw a laser dot onto a 2D context whose drawing surface shows the source
 * frame `object-fit: cover`. When the target matches the frame aspect (the
 * recording canvas) this is a direct scale; for the rep's on-screen overlay it
 * reprojects through the same cover fit the <video> uses.
 */
export function drawLaser(
  ctx: CanvasRenderingContext2D,
  targetW: number,
  targetH: number,
  frameW: number,
  frameH: number,
  pt: LaserPoint,
  color: string,
): void {
  if (!pt.active && Date.now() - pt.at > TRAIL_MS) return;

  const scale = Math.max(targetW / frameW, targetH / frameH);
  const dw = frameW * scale;
  const dh = frameH * scale;
  const offX = (targetW - dw) / 2;
  const offY = (targetH - dh) / 2;
  const cx = offX + pt.x * frameW * scale;
  const cy = offY + pt.y * frameH * scale;

  const fade = pt.active ? 1 : Math.max(0, 1 - (Date.now() - pt.at) / TRAIL_MS);
  const r = Math.max(6, targetH * 0.012);

  ctx.save();
  ctx.globalAlpha = 0.22 * fade;
  ctx.fillStyle = color;
  ctx.beginPath();
  ctx.arc(cx, cy, r * 2.4, 0, Math.PI * 2);
  ctx.fill();

  ctx.globalAlpha = fade;
  ctx.beginPath();
  ctx.arc(cx, cy, r, 0, Math.PI * 2);
  ctx.fill();

  ctx.globalAlpha = fade;
  ctx.strokeStyle = "#ffffff";
  ctx.lineWidth = Math.max(1.5, r * 0.28);
  ctx.beginPath();
  ctx.arc(cx, cy, r, 0, Math.PI * 2);
  ctx.stroke();
  ctx.restore();
}

function clamp01(n: number): number {
  return n < 0 ? 0 : n > 1 ? 1 : n;
}
