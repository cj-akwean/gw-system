"use client";

import Link from "next/link";
import { Link2, Receipt, Wallet } from "lucide-react";
import { useAuth } from "@/lib/auth-context";

const steps = [
  {
    icon: Link2,
    title: "Link your meter",
    body: "Connect your account and meter number — you'll find them on your latest bill.",
  },
  {
    icon: Receipt,
    title: "View bills & usage",
    body: "See your unpaid bills, billing periods, and totals at a glance.",
  },
  {
    icon: Wallet,
    title: "Pay online",
    body: "Settle with card or GCash in a few taps, right from your phone.",
  },
];

export function HowItWorks() {
  const { isAuthenticated, ready } = useAuth();
  const ctaHref = ready && isAuthenticated ? "/dashboard" : "/auth";

  return (
    <section
      aria-label="How it works"
      className="relative py-20 md:py-28"
      id="how-it-works"
    >
      <div className="mx-auto w-full max-w-5xl px-6 md:px-12">
        <div className="mx-auto max-w-xl text-center">
          <h2 className="text-2xl font-bold tracking-tight md:text-3xl">
            How it works
          </h2>
          <p className="mt-2 text-sm text-muted-foreground md:text-base">
            From meter to payment in three easy steps.
          </p>
        </div>

        <div className="mt-12 grid gap-6 sm:grid-cols-3">
          {steps.map((step, index) => {
            const Icon = step.icon;
            return (
              <div
                className="relative rounded-2xl border border-border bg-card/60 p-6"
                key={step.title}
              >
                <div className="flex size-11 items-center justify-center rounded-xl bg-primary/10">
                  <Icon aria-hidden className="size-5 text-primary" />
                </div>
                <p className="mt-4 text-xs font-semibold tracking-widest text-muted-foreground uppercase">
                  Step {index + 1}
                </p>
                <h3 className="mt-1 font-semibold">{step.title}</h3>
                <p className="mt-1.5 text-sm leading-6 text-muted-foreground">
                  {step.body}
                </p>
              </div>
            );
          })}
        </div>

        <div className="mt-10 text-center">
          <Link
            href={ctaHref}
            className="inline-block rounded-md bg-primary px-8 py-3 text-xs font-semibold tracking-widest text-primary-foreground uppercase transition-colors hover:bg-primary/80"
          >
            Get Started
          </Link>
        </div>
      </div>
    </section>
  );
}
