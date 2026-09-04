import { SiteHeader } from "./components/SiteHeader";

export default function Home() {
  return (
    <>
      <SiteHeader />
      <main>
        <section className="hero">
          <div className="hero__glow hero__glow--one" />
          <div className="hero__glow hero__glow--two" />
          <div className="shell hero__content">
            <p className="eyebrow">Ottawa · Gatineau · Long distance</p>
            <h1>See the move.<br />Quote it right.</h1>
            <p className="hero__lede">
              Tell us where you’re going, then record a private walkthrough from your phone.
              Tom Moving reviews the real scope of your move and sends a clear estimate within one business day.
            </p>
            <div className="button-row">
              <a className="button button--primary" href="https://tommoving.ca/estimate/">Start my free estimate</a>
              <a className="button button--quiet" href="#how-it-works">See how it works</a>
            </div>
            <div className="trust-row" aria-label="Estimate benefits">
              <span>Free, no obligation</span><span>Secure video upload</span><span>No app required</span>
            </div>
          </div>
        </section>

        <section className="shell section" id="how-it-works">
          <div className="section-heading">
            <p className="eyebrow">Three simple steps</p>
            <h2>A better estimate without an in-home appointment</h2>
          </div>
          <div className="steps-grid">
            {[
              ["01", "Share the essentials", "Add your addresses, move date, home size, and anything needing special care."],
              ["02", "Walk us through", "Use your phone to record each room and the belongings that are coming with you."],
              ["03", "Receive your quote", "We review the details and send a personalized, no-obligation estimate within 24 hours."],
            ].map(([number, title, copy]) => (
              <article className="step-card" key={number}>
                <span className="step-card__number">{number}</span><h3>{title}</h3><p>{copy}</p>
              </article>
            ))}
          </div>
        </section>

        <section className="feature-band">
          <div className="shell feature-grid">
            <div><p className="eyebrow eyebrow--light">Built for real moving days</p><h2>Fewer surprises. A quote that reflects your home.</h2></div>
            <div className="feature-list">
              <div><strong>Private by design</strong><span>Your recording is stored securely and visible only to authorized Tom Moving staff.</span></div>
              <div><strong>Works from your phone</strong><span>Record in the browser with front or rear camera—no download and no account.</span></div>
              <div><strong>Easy to redo</strong><span>Review the walkthrough before uploading and retake it whenever you need.</span></div>
            </div>
          </div>
        </section>

        <section className="shell section final-cta">
          <p className="eyebrow">Ready when you are</p>
          <h2>Make the first step of your move the easiest one.</h2>
          <a className="button button--primary" href="https://tommoving.ca/estimate/">Get my free estimate</a>
        </section>
      </main>
      <footer className="site-footer">
        <div className="shell site-footer__inner">
          <p>© 2026 Tom Moving. Ottawa–Gatineau.</p>
          <div><a href="https://tommoving.ca/estimate/">Get estimate</a></div>
        </div>
      </footer>
    </>
  );
}
