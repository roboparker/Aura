/**
 * Madori logo + wordmark for the auth pages. The icon is a rounded outline
 * square with an inner solid square in the brand teal, surrounded by a
 * soft glow via `drop-shadow`. Sized for the sign-in / sign-up header.
 */
const MadoriWordmark = () => (
  <div className="flex items-center gap-3">
    <svg
      viewBox="0 0 32 32"
      width="40"
      height="40"
      fill="none"
      aria-hidden="true"
      className="text-primary animate-madori-glow"
    >
      {/* Solid background-coloured inner rect fills the hollow center
          so `drop-shadow` only paints a glow around the OUTER edge of
          the rounded square — without it, the filter halos into the
          transparent middle too. */}
      <rect
        x="6"
        y="6"
        width="20"
        height="20"
        rx="5"
        fill="var(--background)"
      />
      <rect
        x="4"
        y="4"
        width="24"
        height="24"
        rx="7"
        stroke="currentColor"
        strokeWidth="5"
      />
    </svg>
    <span className="text-3xl font-bold tracking-tight text-foreground">Madori</span>
  </div>
);

export default MadoriWordmark;
