import { OpeningAnimation } from "@/components/opening-animation";
import Hero33 from "@/components/ui/hero-33";

export default function Home() {
  return (
    <OpeningAnimation>
      <div className="relative min-h-screen w-full" style={{ background: "var(--bg)" }}>
        <div
          className="pointer-events-none fixed inset-0"
          style={{ background: "var(--glow) no-repeat", filter: "blur(var(--glow-blur))" }}
        />
        <div
          className="pointer-events-none fixed inset-0"
          style={{
            backgroundImage:
              "radial-gradient(circle at 1px 1px, var(--dot) 1px, transparent 0)",
            backgroundSize: "20px 20px",
          }}
        />
        <Hero33
          logoText="Guinobatan Waterworks"
          titleLines={['Every Drop', 'Matters.']}
          description="Delivering clean, reliable water to every home in Guinobatan."
        />
      </div>
    </OpeningAnimation>
  );
}
