import { Clock, MapPin, Phone } from "lucide-react";

export function ContactSection() {
  return (
    <section
      aria-label="Contact"
      className="relative border-t border-border/60 bg-muted/30 py-16 md:py-20"
      id="contact"
    >
      <div className="mx-auto w-full max-w-5xl px-6 md:px-12">
        <div className="mx-auto max-w-xl text-center">
          <h2 className="text-2xl font-bold tracking-tight md:text-3xl">
            Visit or call us
          </h2>
          <p className="mt-2 text-sm text-muted-foreground md:text-base">
            Questions about your bill? Our office is here to help.
          </p>
        </div>

        <div className="mt-10 grid gap-4 sm:grid-cols-3">
          <div className="flex items-start gap-3 rounded-2xl border border-border bg-card p-5">
            <MapPin aria-hidden className="mt-0.5 size-5 shrink-0 text-primary" />
            <div>
              <p className="text-sm font-semibold">Office address</p>
              <p className="mt-1 text-sm leading-6 text-muted-foreground">
                Guinobatan Waterworks Office, Guinobatan, Albay
              </p>
            </div>
          </div>
          <div className="flex items-start gap-3 rounded-2xl border border-border bg-card p-5">
            <Phone aria-hidden className="mt-0.5 size-5 shrink-0 text-primary" />
            <div>
              <p className="text-sm font-semibold">Phone</p>
              <p className="mt-1 text-sm leading-6 text-muted-foreground">
                (052) 000-0000
              </p>
            </div>
          </div>
          <div className="flex items-start gap-3 rounded-2xl border border-border bg-card p-5">
            <Clock aria-hidden className="mt-0.5 size-5 shrink-0 text-primary" />
            <div>
              <p className="text-sm font-semibold">Office hours</p>
              <p className="mt-1 text-sm leading-6 text-muted-foreground">
                Monday – Friday, 8:00 AM – 5:00 PM
              </p>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
