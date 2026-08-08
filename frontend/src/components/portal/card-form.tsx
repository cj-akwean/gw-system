"use client";

import { forwardRef, useImperativeHandle, useState, type ReactNode } from "react";
import {
  digitsOnly,
  expiryIsValid,
  formatCardNumber,
  formatExpiry,
  luhnValid,
  parseExpiry,
} from "@/lib/card-utils";
import { InfoTip } from "@/components/ui/info-tip";

export interface CardPayload {
  details: {
    card_number: string;
    exp_month: number;
    exp_year: number;
    cvc: string;
  };
  billing: {
    name: string;
    email: string;
    phone?: string;
    address: {
      line1: string;
      line2?: string;
      city: string;
      postal_code: string;
      country: string;
    };
  };
}

export interface CardFormHandle {
  /** Validates the form; when clean, calls onSubmit with the card payload. */
  submit: () => void;
}

interface CardFormProps {
  userEmail?: string;
  onSubmit: (payload: CardPayload) => void;
}

type Errors = Partial<Record<"number" | "expiry" | "cvc" | "first" | "last" | "address" | "city" | "zip" | "phone", string>>;

export const CardForm = forwardRef<CardFormHandle, CardFormProps>(
  function CardForm({ userEmail, onSubmit }, ref) {
    const [number, setNumber] = useState("");
    const [expiry, setExpiry] = useState("");
    const [cvc, setCvc] = useState("");
    const [first, setFirst] = useState("");
    const [last, setLast] = useState("");
    const [address, setAddress] = useState("");
    const [address2, setAddress2] = useState("");
    const [city, setCity] = useState("");
    const [zip, setZip] = useState("");
    const [phone, setPhone] = useState("");
    const [errors, setErrors] = useState<Errors>({});

    const clearError = (key: keyof Errors) =>
      setErrors((prev) => {
        if (!prev[key]) return prev;
        const next = { ...prev };
        delete next[key];
        return next;
      });

    const validate = (): CardPayload | null => {
      const next: Errors = {};

      const numberDigits = digitsOnly(number);
      if (numberDigits === "" || !luhnValid(numberDigits)) {
        next.number = "Enter a valid card number.";
      }

      const { month, year } = parseExpiry(expiry);
      if (month === null || year === null || !expiryIsValid(month, year)) {
        next.expiry = "Enter a valid expiry date (MM/YY).";
      }

      const cvcDigits = digitsOnly(cvc);
      if (cvcDigits.length < 3 || cvcDigits.length > 4) {
        next.cvc = "Enter the security code on your card.";
      }

      if (first.trim() === "") next.first = "First name is required.";
      if (last.trim() === "") next.last = "Last name is required.";
      if (address.trim() === "") next.address = "Address is required.";
      if (city.trim() === "") next.city = "City is required.";
      if (zip.trim() === "") next.zip = "ZIP / postal code is required.";

      const phoneDigits = digitsOnly(phone);
      if (phone.trim() !== "" && phoneDigits.length < 7) {
        next.phone = "Enter a valid phone number.";
      }

      setErrors(next);

      if (Object.keys(next).length > 0 || month === null || year === null) {
        return null;
      }

      return {
        details: {
          card_number: numberDigits,
          exp_month: month,
          exp_year: year,
          cvc: cvcDigits,
        },
        billing: {
          name: `${first.trim()} ${last.trim()}`.trim(),
          email: userEmail ?? "",
          ...(phone.trim() !== "" ? { phone: phoneDigits } : {}),
          address: {
            line1: address.trim(),
            ...(address2.trim() !== "" ? { line2: address2.trim() } : {}),
            city: city.trim(),
            postal_code: zip.trim(),
            country: "PH",
          },
        },
      };
    };

    useImperativeHandle(ref, () => ({
      submit() {
        const payload = validate();
        if (payload) {
          onSubmit(payload);
        }
      },
    }));

    const inputClass =
      "w-full rounded-md border border-border bg-background px-3 py-2 text-sm outline-none transition-colors focus:border-ring focus:ring-1 focus:ring-ring";
    const errorClass = "mt-1 text-xs text-destructive";

    const field = (
      label: string,
      id: string,
      value: string,
      onChange: (v: string) => void,
      opts: {
        type?: string;
        inputMode?: "numeric" | "text";
        maxLength?: number;
        autoComplete?: string;
        placeholder?: string;
        error?: string;
        testId: string;
        labelExtra?: ReactNode;
      }
    ) => (
      <div>
        <div className="mb-1 flex items-center gap-1.5">
          <label htmlFor={id} className="text-xs font-medium text-muted-foreground">
            {label}
          </label>
          {opts.labelExtra}
        </div>
        <input
          id={id}
          type={opts.type ?? "text"}
          inputMode={opts.inputMode}
          maxLength={opts.maxLength}
          autoComplete={opts.autoComplete}
          placeholder={opts.placeholder}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          data-testid={opts.testId}
          className={inputClass}
          aria-invalid={opts.error ? true : undefined}
        />
        {opts.error && <p role="alert" className={errorClass}>{opts.error}</p>}
      </div>
    );

    return (
      <div className="space-y-4">
        <div className="space-y-3">
          <h3 className="text-xs font-semibold uppercase tracking-widest text-muted-foreground">
            Card details
          </h3>
          {field("Card number", "card-number", number, (v) => {
            setNumber(formatCardNumber(v));
            clearError("number");
          }, {
            inputMode: "numeric",
            maxLength: 23,
            autoComplete: "cc-number",
            placeholder: "1234 5678 9012 3456",
            error: errors.number,
            testId: "card-number",
          })}
          <div className="grid grid-cols-2 gap-3">
            {field("Expiry (MM/YY)", "card-expiry", expiry, (v) => {
              setExpiry(formatExpiry(v));
              clearError("expiry");
            }, {
              inputMode: "numeric",
              maxLength: 5,
              autoComplete: "cc-exp",
              placeholder: "MM/YY",
              error: errors.expiry,
              testId: "card-expiry",
            })}
            {field("Security code", "card-cvc", cvc, (v) => {
              setCvc(digitsOnly(v).slice(0, 4));
              clearError("cvc");
            }, {
              inputMode: "numeric",
              maxLength: 4,
              autoComplete: "cc-csc",
              placeholder: "123",
              error: errors.cvc,
              testId: "card-cvc",
              labelExtra: (
                <InfoTip
                  content="The 3-digit code on the back of your card."
                  label="Where to find the security code"
                />
              ),
            })}
          </div>
        </div>

        <div className="space-y-3">
          <h3 className="text-xs font-semibold uppercase tracking-widest text-muted-foreground">
            Billing information
          </h3>
          <div className="grid grid-cols-2 gap-3">
            {field("First name", "card-first-name", first, (v) => {
              setFirst(v);
              clearError("first");
            }, {
              autoComplete: "given-name",
              error: errors.first,
              testId: "card-first-name",
            })}
            {field("Last name", "card-last-name", last, (v) => {
              setLast(v);
              clearError("last");
            }, {
              autoComplete: "family-name",
              error: errors.last,
              testId: "card-last-name",
            })}
          </div>
          {field("Address", "card-address", address, (v) => {
            setAddress(v);
            clearError("address");
          }, {
            autoComplete: "address-line1",
            error: errors.address,
            testId: "card-address",
          })}
          {field("Address 2 (optional)", "card-address2", address2, (v) => {
            setAddress2(v);
          }, {
            autoComplete: "address-line2",
            testId: "card-address2",
          })}
          <div className="grid grid-cols-2 gap-3">
            {field("City", "card-city", city, (v) => {
              setCity(v);
              clearError("city");
            }, {
              autoComplete: "address-level2",
              error: errors.city,
              testId: "card-city",
            })}
            {field("ZIP / postal code", "card-zip", zip, (v) => {
              setZip(v);
              clearError("zip");
            }, {
              inputMode: "numeric",
              autoComplete: "postal-code",
              error: errors.zip,
              testId: "card-zip",
            })}
          </div>
          {field("Phone (optional)", "card-phone", phone, (v) => {
            setPhone(digitsOnly(v).slice(0, 15));
            clearError("phone");
          }, {
            inputMode: "numeric",
            autoComplete: "tel",
            error: errors.phone,
            testId: "card-phone",
          })}
          <p className="text-xs text-muted-foreground">
            Country: Philippines · Email on file: {userEmail ?? "—"}
          </p>
        </div>
      </div>
    );
  }
);
