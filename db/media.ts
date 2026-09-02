import { env } from "cloudflare:workers";

type StoredMedia = {
  body: ReadableStream;
  size: number;
  httpMetadata?: { contentType?: string };
};

type MediaBucket = {
  put(
    key: string,
    body: ReadableStream,
    options?: { httpMetadata?: { contentType?: string } },
  ): Promise<unknown>;
  get(key: string): Promise<StoredMedia | null>;
  delete(key: string): Promise<void>;
};

type RuntimeBindings = { MEDIA?: MediaBucket };

export function mediaBucket() {
  const bucket = (env as unknown as RuntimeBindings).MEDIA;
  if (!bucket) throw new Error("Video storage is not configured.");
  return bucket;
}
