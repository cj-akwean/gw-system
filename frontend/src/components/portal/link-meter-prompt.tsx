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
      className="mb-6 rounded-xl border border-dashed border-border bg-card/60 p-5"
    >
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-start gap-3">
          <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10">
            <Link2Icon aria-hidden className="size-4 text-primary" />
          </div>
          <div>
            <p className="font-medium text-sm">Link your meter</p>
            <p className="mt-0.5 text-muted-foreground text-sm leading-6">
              Connect your account and meter number to see your bills and usage
              here.
            </p>
          </div>
        </div>
        <Button
          className="h-9 shrink-0 text-sm"
          onClick={() => router.push("/onboarding")}
          type="button"
        >
          Link Meter
        </Button>
      </div>
    </section>
  );
}
