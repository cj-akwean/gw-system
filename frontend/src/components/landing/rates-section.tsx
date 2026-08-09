"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { Check, Droplets, Loader2 } from "lucide-react";
import { LiquidOcean } from "@/components/ui/liquid-ocean";
import { Button } from "@/components/ui/button";
import { getRates, type PortalRates } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { useTheme } from "@/lib/theme";
import { cn } from "@/lib/utils";

type State =
  | { status: "loading" }
  | { status: "error" }
  | { status: "ready"; rates: PortalRates };

function formatRate(rate: number | null): string {
  if (rate === null) return "—";
  return new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
    minimumFractionDigits: 2,
  }).format(rate);
}

function formatEffectiveDate(iso: string): string {
  const d = new Date(`${iso}T00:00:00`);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleDateString("en-PH", {
    year: "numeric",
    month: "long",
    day: "numeric",
  });
}

export function RatesSection() {
  const { isAuthenticated, ready } = useAuth();
  const { dark } = useTheme();
  const [state, setState] = useState<State>({ status: "loading" });

  useEffect(() => {
    let cancelled = false;
    getRates()
      .then((rates) => {
        if (!cancelled) setState({ status: "ready", rates });
      })
      .catch(() => {
        if (!cancelled) setState({ status: "error" });
      });
    return () => {
      cancelled = true;
    };
  }, []);

  const ctaHref = ready && isAuthenticated ? "/dashboard" : "/auth";

  const featureList = (rates: PortalRates): string[] => {
    const { schedule, penalty } = rates;
    const items: string[] = [];
    if (schedule.type === "flat" && schedule.flat_rate !== null) {
      items.push(
        `${formatRate(schedule.flat_rate)} per cubic meter for every drop you use`
      );
    }
    for (const tier of schedule.tiers) {
      const range =
        tier.max_cu_m !== null
          ? `${tier.min_cu_m}–${tier.max_cu_m} m³`
          : `${tier.min_cu_m}+ m³`;
      items.push(
        `${range} billed at ${formatRate(tier.rate_per_cu_m)}/m³`
      );
    }
    if (penalty) {
      items.push(
        `${penalty.percent_per_month}% monthly penalty on unpaid balances`
      );
      items.push(`${penalty.grace_period_days}-day grace period after the due date`);
      items.push(`Disconnection after ${penalty.disconnection_after_days} days overdue`);
    }
    return items;
  };

  return (
    <section
      aria-label="Water rates"
      className="relative overflow-hidden py-20 md:py-28"
      id="rates"
    >
      <LiquidOcean
        accentColor={0x7dd3fc}
        backgroundColor={dark ? 0x1a4a66 : 0x0d2b3e}
        boatCount={0}
        fov={20}
        oceanFragments={18}
        oceanOpacity={0.6}
        oceanSize={30}
        showBoats={false}
        showGrid={false}
        showWireframe
        waveAmplitude={0.2}
        waveSpeed={0.04}
        className="absolute inset-0 min-h-full"
      />
      <div
        aria-hidden
        className="pointer-events-none absolute inset-0"
        style={{
          background:
            "radial-gradient(ellipse 60% 80% at 0% 50%, var(--primary), transparent 80%)",
          opacity: dark ? 0.15 : 0.2,
        }}
      />

      <div className="relative z-10 mx-auto grid w-full max-w-5xl grid-cols-1 items-center gap-6 px-6 md:grid-cols-5 md:gap-0 md:px-12">
        <div className="flex h-min flex-col justify-between space-y-8 rounded-2xl border border-white/10 bg-background/60 p-6 shadow-xl shadow-black/20 backdrop-blur-md md:col-span-2 md:my-4 md:rounded-r-none md:border-r-0 lg:p-10">
          <div className="space-y-4">
            <div>
              <h2 className="text-sm font-semibold tracking-widest text-primary uppercase">
                {state.status === "ready" ? state.rates.schedule.name : "Water Rates"}
              </h2>
              {state.status === "ready" ? (
                <>
                  <div className="my-4 flex items-end gap-1.5">
                    <span className="text-5xl font-bold tracking-tighter md:text-6xl">
                      {formatRate(state.rates.schedule.flat_rate)}
                    </span>
                    <span className="pb-1.5 text-lg font-light text-muted-foreground">
                      / m³
                    </span>
                  </div>
                  <p className="text-sm text-muted-foreground">
                    Flat rate per cubic meter, effective{" "}
                    {formatEffectiveDate(state.rates.schedule.effective_from)}.
                  </p>
                </>
              ) : (
                <>
                  <div className="my-4 flex items-center gap-2">
                    {state.status === "loading" ? (
                      <Loader2 aria-hidden className="size-5 animate-spin text-primary" />
                    ) : (
                      <Droplets aria-hidden className="size-5 text-primary" />
                    )}
                    <span className="text-4xl font-bold tracking-tighter">
                      {state.status === "loading" ? "…" : "Ask the office"}
                    </span>
                  </div>
                  <p className="text-sm text-muted-foreground">
                    {state.status === "loading"
                      ? "Loading current rates…"
                      : "Current rates are temporarily unavailable. Please contact the office for the latest schedule."}
                  </p>
                </>
              )}
            </div>

            <Button asChild size="lg" variant="default">
              <Link href={ctaHref}>Pay My Bill</Link>
            </Button>

            <div className="border-t border-border pt-6">
              <h3 className="mb-3 text-xs font-semibold uppercase tracking-widest text-muted-foreground">
                Included
              </h3>
              <ul className="space-y-2.5 text-sm">
                {(state.status === "ready"
                  ? featureList(state.rates)
                  : [
                      "2% monthly penalty on unpaid balances",
                      "15-day grace period after the due date",
                      "Disconnection after 60 days overdue",
                    ]
                ).map((item) => (
                  <li className="flex items-center gap-2.5" key={item}>
                    <Check aria-hidden className="size-4 shrink-0 text-primary" />
                    {item}
                  </li>
                ))}
              </ul>
              <p className="mt-4 text-xs text-muted-foreground">
                *Rates as posted by the waterworks office. Penalty and
                disconnection policies may be updated with public notice.
              </p>
            </div>
          </div>
        </div>

        <div className="rounded-2xl border border-white/10 bg-background/70 p-6 shadow-xl shadow-black/20 backdrop-blur-md md:col-span-3 lg:p-10">
          <div className="space-y-4">
            <div>
              <h2 className="text-xl font-semibold tracking-tight">
                How billing works
              </h2>
              <p className="mt-1 text-sm text-muted-foreground">
                Simple, transparent billing — no hidden charges.
              </p>
            </div>

            <ul className="space-y-4">
              {[
                {
                  title: "We read your meter",
                  body: "Readings are taken each billing period and recorded on your account.",
                },
                {
                  title: "Your bill is computed",
                  body: "Cubic meters used × the current rate, plus any penalty from previous unpaid balances.",
                },
                {
                  title: "Pay before the due date",
                  body: "Settle online through the customer portal or in person at the office.",
                },
              ].map((step, index) => (
                <li
                  className="flex items-start gap-4 rounded-xl border border-border/60 bg-card/60 p-4"
                  key={step.title}
                >
                  <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-sm font-bold text-primary">
                    {index + 1}
                  </div>
                  <div className="min-w-0">
                    <p className="font-semibold text-sm">{step.title}</p>
                    <p className="mt-0.5 text-sm leading-6 text-muted-foreground">
                      {step.body}
                    </p>
                  </div>
                </li>
              ))}
            </ul>

            <p className="rounded-xl border border-dashed border-border bg-muted/40 p-4 text-xs leading-5 text-muted-foreground">
              <span className={cn("font-semibold text-foreground")}>Looking for your bill?</span>{" "}
              Link your account and meter number to see your bills, usage, and due
              dates online.
            </p>
          </div>
        </div>
      </div>
    </section>
  );
}
