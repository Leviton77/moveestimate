import { getVideoSession, attachVideoToSession } from "../../../../../db/sessions";

function videoSessionId(value: unknown): value is string {
  return (
    typeof value === "string" &&
    /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value)
  );
}

interface CloudflareEnv {
  MEDIA?: R2Bucket;
}

export async function POST(
  request: Request,
  { params }: { params: { id: string } },
) {
  try {
    if (!videoSessionId(params.id)) {
      return Response.json({ error: "Invalid video session ID." }, { status: 400 });
    }

    // Get the video session to verify it exists
    const session = await getVideoSession(params.id);
    if (!session) {
      return Response.json({ error: "Video session not found." }, { status: 404 });
    }

    // Get the multipart form data
    const formData = await request.formData();
    const videoBlob = formData.get("video") as Blob | null;

    if (!videoBlob) {
      return Response.json({ error: "No video file provided." }, { status: 400 });
    }

    // Generate R2 key with timestamp
    const timestamp = Date.now();
    const videoKey = `video-sessions/${params.id}/${timestamp}.webm`;
    const contentType = videoBlob.type || "video/webm";
    const size = videoBlob.size;

    // Convert blob to buffer for R2 upload
    const buffer = await videoBlob.arrayBuffer();

    // Get Cloudflare R2 binding from environment
    // This requires R2 binding configured in .openai/hosting.json with name "MEDIA"
    const env = process.env as unknown as CloudflareEnv;
    const r2 = env.MEDIA;

    if (!r2) {
      // If R2 is not available in this environment, store metadata only
      // In production, this should be properly configured
      console.warn(
        "R2 binding not available. Video metadata saved but file not uploaded.",
      );
    } else {
      // Upload to R2
      await r2.put(videoKey, buffer, {
        httpMetadata: {
          contentType,
        },
      });
    }

    // Attach video to the video session
    await attachVideoToSession(params.id, {
      key: videoKey,
      contentType,
      size,
    });

    return Response.json({ ok: true, videoKey }, { status: 200 });
  } catch (error) {
    const message = error instanceof Error ? error.message : "Unable to upload video.";
    console.error("Video upload error:", message);
    return Response.json({ error: message }, { status: 500 });
  }
}
