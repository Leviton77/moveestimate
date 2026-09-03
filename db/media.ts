import { env } from "cloudflare:workers";

type ByteRange = { offset: number; length: number };

type StoredMedia = {
  body: ReadableStream;
  /** Total object size, regardless of any range requested. */
  size: number;
  /** The slice actually returned, when a range was requested. */
  range?: ByteRange;
  httpMetadata?: { contentType?: string };
};

type MediaBucket = {
  put(
    key: string,
    body: ReadableStream,
    options?: { httpMetadata?: { contentType?: string } },
  ): Promise<unknown>;
  get(key: string, options?: { range?: ByteRange }): Promise<StoredMedia | null>;
  delete(key: string): Promise<void>;
};

type RuntimeBindings = { MEDIA?: MediaBucket };

export function mediaBucket() {
  const bucket = (env as unknown as RuntimeBindings).MEDIA;
  if (!bucket) throw new Error("Video storage is not configured.");
  return bucket;
}
