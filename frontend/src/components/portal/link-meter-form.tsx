"use client";

import { useState, type FormEvent } from "react";
import { Loader2, Search } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { ApiError, createLink, type PortalLink } from "@/lib/api";

interface LinkMeterFormProps {
  onLinked: (link: PortalLink) => void;
  onSkip?: () => void;
}

export function LinkMeterForm({ onLinked, onSkip }: LinkMeterFormProps) {
  const [accountNumber, setAccountNumber] = useState("");
  const [meterNumber, setMeterNumber] = useState("");
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState("");

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    if (isLoading) return;
    setIsLoading(true);
    setError("");

    try {
      const link = await createLink(accountNumber.trim(), meterNumber.trim());
      onLinked(link);
    } catch (err) {
      if (err instanceof ApiError) {
        if (err.status === 404) {
          setError("We couldn't find an active connection with that account and meter number.");
        } else if (err.status === 409) {
          setError("This meter is already linked to another account.");
        } else {
          setError(err.message);
        }
      } else {
        setError("Something went wrong. Please try again.");
      }
    } finally {
      setIsLoading(false);
    }
  }

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h2 className="font-semibold text-xl tracking-tight">Link Your Meter</h2>
        <p className="text-muted-foreground text-sm">
          Find these on your latest bill. Linking lets us show your bills and
          due dates here.
        </p>
      </div>

      <form className="space-y-4" onSubmit={handleSubmit}>
        <div className="space-y-2">
          <label className="font-medium text-sm" htmlFor="account-number">
            Account Number
          </label>
          <div className="relative">
            <Input
              autoComplete="off"
              className="h-10 pl-9 text-sm"
              id="account-number"
              maxLength={20}
              name="account_number"
              onChange={(e) => setAccountNumber(e.target.value)}
              placeholder="e.g. GW-000123"
              required
              value={accountNumber}
            />
            <Search
              aria-hidden="true"
              className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
            />
          </div>
        </div>

        <div className="space-y-2">
          <label className="font-medium text-sm" htmlFor="meter-number">
            Meter Number
          </label>
          <div className="relative">
            <Input
              autoComplete="off"
              className="h-10 pl-9 text-sm"
              id="meter-number"
              maxLength={20}
              name="meter_number"
              onChange={(e) => setMeterNumber(e.target.value)}
              placeholder="e.g. MTR-001234"
              required
              value={meterNumber}
            />
            <Search
              aria-hidden="true"
              className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
            />
          </div>
        </div>

        {error && <p className="text-destructive text-sm" role="alert">{error}</p>}

        <Button
          className="h-10 w-full text-sm"
          disabled={isLoading}
          type="submit"
        >
          {isLoading ? (
            <Loader2 aria-hidden className="mr-1 h-4 w-4 animate-spin" />
          ) : null}
          Link My Meter
        </Button>
      </form>

      {onSkip && (
        <div className="text-center">
          <button
            type="button"
            onClick={onSkip}
            className="text-sm text-muted-foreground underline underline-offset-4 hover:text-primary transition-colors"
          >
            I&apos;ll do this later
          </button>
        </div>
      )}
    </div>
  );
}
