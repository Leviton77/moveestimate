import type { Metadata } from "next";
import { headers } from "next/headers";
import "./globals.css";

export async function generateMetadata(): Promise<Metadata> {
  const requestHeaders = await headers();
  const host = requestHeaders.get("x-forwarded-host") ?? requestHeaders.get("host");
  const protocol = requestHeaders.get("x-forwarded-proto") ?? "https";
  const origin = host ? `${protocol}://${host}` : "https://tommoving.ca";
  return {
    title: { default: "MoveEstimate | Tom Moving", template: "%s | MoveEstimate" },
    description: "Request an accurate moving estimate from Tom Moving with a secure video walkthrough.",
    icons: { icon: "/favicon.svg", shortcut: "/favicon.svg" },
    openGraph: {
      title: "MoveEstimate — a clearer moving quote",
      description: "Share your move details and a secure walkthrough. Receive a detailed quote within one business day.",
      type: "website",
      images: [{ url: `${origin}/og.png`, width: 1200, height: 630, alt: "MoveEstimate by Tom Moving" }],
    },
    twitter: {
      card: "summary_large_image",
      title: "MoveEstimate — a clearer moving quote",
      description: "Share your move details and a secure walkthrough for an accurate quote.",
      images: [`${origin}/og.png`],
    },
  };
}

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <html lang="en"><body>{children}</body></html>;
}
