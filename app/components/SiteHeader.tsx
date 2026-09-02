import Link from "next/link";

export function SiteHeader() {
  return (
    <header className="site-header">
      <div className="site-header__inner">
        <Link href="/" className="wordmark" aria-label="MoveEstimate home">
          <span className="wordmark__mark" aria-hidden="true">M</span>
          <span>MoveEstimate</span>
        </Link>
        <nav className="site-nav" aria-label="Main navigation">
          <Link href="/estimate">Get estimate</Link>
          <Link href="/rep">Rep portal</Link>
        </nav>
      </div>
    </header>
  );
}
