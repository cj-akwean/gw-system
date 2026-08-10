import dynamic from 'next/dynamic';
import { OpeningAnimation } from "@/components/opening-animation";

const Hero33 = dynamic(() => import('@/components/ui/hero-33'));
const LandingBackdrop = dynamic(() => import('@/components/landing/landing-backdrop').then(m => ({ default: m.LandingBackdrop })));
const FlowLine = dynamic(() => import('@/components/landing/flow-line').then(m => ({ default: m.FlowLine })));
const RatesSection = dynamic(() => import('@/components/landing/rates-section').then(m => ({ default: m.RatesSection })));
const HowItWorks = dynamic(() => import('@/components/landing/how-it-works').then(m => ({ default: m.HowItWorks })));
const ContactSection = dynamic(() => import('@/components/landing/contact-section').then(m => ({ default: m.ContactSection })));

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
        <RatesSection />
        <HowItWorks />
        <ContactSection />
      </div>
    </OpeningAnimation>
  );
}
