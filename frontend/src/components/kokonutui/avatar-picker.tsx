"use client";

/**
 * @author: @dorianbaffier
 * @description: Avatar Picker
 * @version: 2.0.0
 * @date: 2026-02-22
 * @license: MIT
 * @website: https://kokonutui.com
 * @github: https://github.com/kokonut-labs/kokonutui
 */

import { Check, ChevronRight, Phone, User2 } from "lucide-react";
import type { Variants } from "motion/react";
import { AnimatePresence, motion, useReducedMotion } from "motion/react";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { AVATARS, AVATAR_RGB, getAvatar, type Avatar } from "@/lib/avatars";
import { cn } from "@/lib/utils";

interface ProfileSetupProps {
  onComplete?: (data: {
    name: string;
    avatarId: number;
    phone: string | null;
  }) => void;
  className?: string;
  initialName?: string;
  initialAvatarId?: number;
  /** Show an optional phone field (settings + onboarding). */
  initialPhone?: string;
  withPhone?: boolean;
  heading?: string;
  subtitle?: string;
  submitLabel?: string;
}

const containerVariants: Variants = {
  initial: { opacity: 0 },
  animate: {
    opacity: 1,
    transition: { staggerChildren: 0.06, delayChildren: 0.05 },
  },
};

const thumbnailVariants: Variants = {
  initial: { opacity: 0, y: 6 },
  animate: {
    opacity: 1,
    y: 0,
    transition: { duration: 0.28, ease: "easeOut" },
  },
};

export default function ProfileSetup({
  onComplete,
  className,
  initialName = "",
  initialAvatarId,
  initialPhone = "",
  withPhone = false,
  heading = "Pick Your Avatar",
  subtitle = "Choose one to get started",
  submitLabel = "Get Started",
}: ProfileSetupProps) {
  const [selectedAvatar, setSelectedAvatar] = useState<Avatar>(
    initialAvatarId ? getAvatar(initialAvatarId) : AVATARS[0]
  );
  const [name, setName] = useState(initialName);
  const [phone, setPhone] = useState(initialPhone);
  const [isFocused, setIsFocused] = useState(false);
  const shouldReduceMotion = useReducedMotion();

  const handleAvatarSelect = (avatar: Avatar) => {
    if (avatar.id === selectedAvatar.id) return;
    setSelectedAvatar(avatar);
  };

  const handleSubmit = () => {
    if (name.trim() && onComplete) {
      onComplete({
        name: name.trim(),
        avatarId: selectedAvatar.id,
        phone: withPhone ? phone.trim() || null : null,
      });
    }
  };

  const isValid = name.trim().length >= 3;
  const showError = name.trim().length > 0 && name.trim().length < 3;
  const rgb = AVATAR_RGB[selectedAvatar.id];

  return (
    <Card
      className={cn(
        "relative mx-auto w-full max-w-[400px] border-border bg-card",
        className
      )}
    >
      <CardContent className="p-8">
        <div className="space-y-8">
          {/* Header */}
          <div className="space-y-1 text-center">
            <h2 className="font-semibold text-xl tracking-tight">
              {heading}
            </h2>
            <p className="text-muted-foreground text-sm">
              {subtitle}
            </p>
          </div>

          {/* Avatar Stage */}
          <div className="flex flex-col items-center gap-4">
            {/*
             * Two-div approach: outer div holds the animated color ring
             * (no overflow-hidden so box-shadow renders cleanly),
             * inner div clips the avatar SVG.
             * scale-[4] fills the 160px circle with the avatar's background.
             */}
            <div className="relative h-40 w-40">
              {/* Animated per-avatar color ring */}
              <motion.div
                animate={{
                  boxShadow: `0 0 0 2px rgba(${rgb}, 0.55), 0 6px 24px rgba(${rgb}, 0.18)`,
                }}
                aria-hidden="true"
                className="pointer-events-none absolute inset-0 rounded-full"
                transition={
                  shouldReduceMotion
                    ? { duration: 0 }
                    : { duration: 0.45, ease: "easeOut" }
                }
              />

              {/* Avatar circle — clips content */}
              <div className="relative h-full w-full overflow-hidden rounded-full">
                <AnimatePresence mode="wait">
                  <motion.div
                    animate={{ opacity: 1 }}
                    className="absolute inset-0 flex items-center justify-center"
                    exit={{ opacity: 0 }}
                    initial={{ opacity: 0 }}
                    key={selectedAvatar.id}
                    transition={
                      shouldReduceMotion
                        ? { duration: 0 }
                        : { duration: 0.2, ease: "easeOut" }
                    }
                  >
                    {/* scale-[4]: 40px SVG × 4 = 160px, fills the circle */}
                    <div className="scale-[4] transform">
                      {selectedAvatar.svg}
                    </div>
                  </motion.div>
                </AnimatePresence>
              </div>
            </div>

            {/* Avatar name — fades with selection */}
            <AnimatePresence mode="wait">
              <motion.span
                animate={{ opacity: 1 }}
                className="text-[11px] text-muted-foreground uppercase tracking-[0.12em]"
                exit={{ opacity: 0 }}
                initial={{ opacity: 0 }}
                key={selectedAvatar.id}
                transition={
                  shouldReduceMotion
                    ? { duration: 0 }
                    : { duration: 0.16, ease: "easeOut" }
                }
              >
                {selectedAvatar.alt}
              </motion.span>
            </AnimatePresence>

            {/* Thumbnail strip */}
            <motion.div
              animate="animate"
              className="flex gap-3"
              initial="initial"
              variants={containerVariants}
            >
              {AVATARS.map((avatar) => {
                const isSelected = selectedAvatar.id === avatar.id;
                return (
                  <motion.button
                    aria-label={`Select ${avatar.alt}`}
                    aria-pressed={isSelected}
                    className={cn(
                      "relative h-14 w-14 overflow-hidden rounded-xl border bg-muted transition-[opacity,box-shadow] duration-200 ease-out",
                      isSelected
                        ? "border-foreground/20 opacity-100 ring-2 ring-foreground/70 ring-offset-2 ring-offset-background"
                        : "border-border opacity-50 hover:opacity-100"
                    )}
                    key={avatar.id}
                    onClick={() => handleAvatarSelect(avatar)}
                    type="button"
                    variants={thumbnailVariants}
                    whileHover={shouldReduceMotion ? {} : { scale: 1.06 }}
                    whileTap={shouldReduceMotion ? {} : { scale: 0.94 }}
                  >
                    <div className="absolute inset-0 flex items-center justify-center">
                      <div className="scale-[2.3] transform">{avatar.svg}</div>
                    </div>
                    {isSelected && (
                      <div className="absolute -right-0.5 -bottom-0.5 flex h-5 w-5 items-center justify-center rounded-full bg-foreground">
                        <Check
                          aria-hidden="true"
                          className="h-3 w-3 text-background"
                        />
                      </div>
                    )}
                  </motion.button>
                );
              })}
            </motion.div>
          </div>

          {/* Name field */}
          <div className="space-y-4">
            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <label className="font-medium text-sm" htmlFor="name">
                  Name
                </label>
                <span
                  className={cn(
                    "text-xs tabular-nums transition-colors duration-200 ease-out",
                    name.length >= 45
                      ? "text-amber-500 dark:text-amber-400"
                      : "text-muted-foreground/50"
                  )}
                >
                  {name.length}/50
                </span>
              </div>

              <div className="relative">
                <Input
                  autoComplete="name"
                  className={cn(
                    "h-10 pl-9 text-sm",
                    showError &&
                      "border-destructive/50 focus-visible:ring-destructive"
                  )}
                  id="name"
                  maxLength={50}
                  name="name"
                  onBlur={() => setIsFocused(false)}
                  onChange={(e) => setName(e.target.value)}
                  onFocus={() => setIsFocused(true)}
                  placeholder="Your name"
                  spellCheck={false}
                  type="text"
                  value={name}
                />
                <User2
                  aria-hidden="true"
                  className={cn(
                    "absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 transition-colors duration-200 ease-out",
                    isFocused ? "text-foreground" : "text-muted-foreground"
                  )}
                />
              </div>

              <AnimatePresence>
                {showError && (
                  <motion.p
                    animate={{ opacity: 1, y: 0 }}
                    className="ml-0.5 text-destructive text-xs"
                    exit={{ opacity: 0, y: -4 }}
                    initial={{ opacity: 0, y: -4 }}
                    role="alert"
                    transition={{ duration: 0.15, ease: "easeOut" }}
                  >
                    Name must be at least 3 characters
                  </motion.p>
                )}
              </AnimatePresence>
            </div>

            {withPhone && (
              <div className="space-y-2 scroll-mt-24">
                <label className="font-medium text-sm" htmlFor="phone">
                  Phone <span className="font-normal text-muted-foreground">(optional)</span>
                </label>
                <div className="relative">
                  <Input
                    autoComplete="tel"
                    className="h-10 pl-9 text-sm"
                    id="phone"
                    inputMode="tel"
                    maxLength={20}
                    name="phone"
                    onChange={(e) => setPhone(e.target.value)}
                    placeholder="09XXXXXXXXX"
                    spellCheck={false}
                    type="tel"
                    value={phone}
                  />
                  <Phone
                    aria-hidden="true"
                    className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                  />
                </div>
                <p className="ml-0.5 text-xs text-muted-foreground">
                  Used to receive verification codes by SMS. Leave empty to keep using email.
                </p>
              </div>
            )}

            <Button
              className="group h-10 w-full text-sm"
              disabled={!isValid}
              onClick={handleSubmit}
              type="button"
            >
              {submitLabel}
              <ChevronRight
                aria-hidden="true"
                className="ml-1 h-4 w-4 transition-transform duration-200 ease-out group-hover:translate-x-0.5"
              />
            </Button>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}
