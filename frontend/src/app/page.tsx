export default function Home() {
  return (
    <div className="relative min-h-screen w-full" style={{ background: "var(--bg)" }}>
      <div
        className="pointer-events-none fixed inset-0"
        style={{ background: "var(--glow) no-repeat", filter: "blur(80px)" }}
      />
      <div
        className="pointer-events-none fixed inset-0"
        style={{
          backgroundImage:
            "radial-gradient(circle at 1px 1px, var(--dot) 1px, transparent 0)",
          backgroundSize: "20px 20px",
        }}
      />
      <div
        className="relative flex min-h-screen items-center justify-center"
        style={{
          color: "var(--text)",
          fontFamily: "system-ui, sans-serif",
          fontSize: "1.5rem",
        }}
      >
        <div
          className="rounded-2xl px-12 py-8 backdrop-blur-md"
          style={{
            background: "var(--card-bg)",
            boxShadow: "var(--card-shadow)",
            border: "1px solid var(--card-border)",
          }}
        >
          ✦ Your Content Goes Here ✦
        </div>
      </div>
    </div>
  );
}
