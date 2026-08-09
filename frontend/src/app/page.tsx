import { OpeningAnimation } from "@/components/opening-animation";
import Hero33 from "@/components/ui/hero-33";
import { LandingBackdrop } from "@/components/landing/landing-backdrop";
import { FlowLine } from "@/components/landing/flow-line";
import { RatesLazy } from "@/components/landing/rates-lazy";
import { HowItWorks } from "@/components/landing/how-it-works";
import { ContactSection } from "@/components/landing/contact-section";

export default function Home() {
  return (
    <OpeningAnimation>
      <div className="relative min-h-screen w-full scroll-smooth" style={{ background: "var(--bg)" }}>
        <div
          className="pointer-events-none fixed inset-0"
          style={{ background: "var(--glow) no-repeat", filter: "blur(var(--glow-blur))" }}
        />
        <LandingBackdrop />
        <FlowLine />
        <Hero33
          logoText="Guinobatan Waterworks"
          titleLines={['Every Drop', 'Matters.']}
          description="Delivering clean, reliable water to every home in Guinobatan."
        />
        <RatesLazy />
        <HowItWorks />
        <ContactSection />
      </div>
    </OpeningAnimation>
  );
}
