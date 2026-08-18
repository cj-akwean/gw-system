"use client";

import * as React from "react";
import { Eye, EyeOff } from "lucide-react";

import { cn } from "@/lib/utils";

function Input({ className, type, ...props }: React.ComponentProps<"input">) {
  const [visible, setVisible] = React.useState(false);
  const isPassword = type === "password";
  const isGroupControl =
    (props as Record<string, unknown>)["data-slot"] === "input-group-control";

  const input = (
    <input
      type={isPassword && visible ? "text" : type}
      data-slot="input"
      className={cn(
        "h-10 w-full min-w-0 border border-transparent border-b-input bg-transparent px-0 py-1 text-base transition-[color,border-color] outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:border-b-ring disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-b-destructive md:text-sm dark:aria-invalid:border-b-destructive/50",
        isPassword && "pr-9",
        className
      )}
      {...props}
    />
  );

  if (!isPassword) {
    return input;
  }

  return (
    <div
      className={cn("relative min-w-0", isGroupControl ? "flex-1" : "w-full")}
    >
      {input}
      <span
        role="button"
        tabIndex={0}
        aria-label={visible ? "Hide password" : "Show password"}
        aria-pressed={visible}
        onMouseDown={(e) => e.preventDefault()}
        onClick={() => setVisible((v) => !v)}
        onKeyDown={(e) => {
          if (e.key === "Enter" || e.key === " ") {
            e.preventDefault();
            setVisible((v) => !v);
          }
        }}
        className="absolute top-1/2 right-0 flex h-9 w-9 -translate-y-1/2 cursor-pointer items-center justify-center text-muted-foreground transition-colors select-none hover:text-foreground"
      >
        {visible ? (
          <EyeOff aria-hidden className="size-4" />
        ) : (
          <Eye aria-hidden className="size-4" />
        )}
      </span>
    </div>
  );
}

export { Input }