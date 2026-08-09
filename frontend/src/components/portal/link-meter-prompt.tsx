"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Link2Icon } from "lucide-react";
import { Button } from "@/components/ui/button";
import { getLinks, type PortalLink } from "@/lib/api";

export function LinkMeterPrompt() {
  const router = useRouter();
  const [links, setLinks] = useState<PortalLink[] | null>(null);

  useEffect(() => {
    let cancelled = false;
    getLinks()
      .then((fetched) => {
        if (!cancelled) setLinks(fetched);
      })
      .catch(() => {
        if (!cancelled) setLinks([]);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  if (links === null) {
    return null;
  }

  if (links.length > 0) {
    return null;
  }

  return (
    <section
      aria-label="Link your meter"
      className="rounded-2xl border border-dashed border-border bg-card/70 p-6 shadow-sm sm:p-5"
    >
      <div className="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
        <div className="flex items-start gap-4">
          <div className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary/10">
            <Link2Icon aria-hidden className="size-5 text-primary" />
          </div>
          <div>
            <p className="font-semibold text-base">Link your meter</p>
            <p className="mt-1 text-sm leading-6 text-muted-foreground">
              Connect your account and meter number to see your bills and usage
              here.
            </p>
          </div>
        </div>
        <Button
          className="h-10 w-full shrink-0 text-sm sm:w-auto sm:self-center"
          onClick={() => router.push("/onboarding")}
          type="button"
        >
          Link Meter
        </Button>
      </div>
    </section>
  );
}
