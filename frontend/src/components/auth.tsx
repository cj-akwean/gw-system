"use client";

import Link from "next/link";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import {
  InputGroup,
  InputGroupAddon,
  InputGroupInput,
} from "@/components/ui/input-group";
import { AuthDivider } from "@/components/auth-divider";
import { DecorIcon } from "@/components/decor-icon";
import { GoogleSignInButton } from "@/components/google-signin-button";
import { AtSignIcon, Loader2, LockIcon } from "lucide-react";
import { useState, type FormEvent } from "react";
import { useAuth } from "@/lib/auth-context";

interface AuthPageProps {
  mode: "login" | "signup";
  onToggleMode?: () => void;
}

export function AuthPage({ mode, onToggleMode }: AuthPageProps) {
  const isLogin = mode === "login";
  const { login, signup } = useAuth();

  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState("");

  // Field-level errors (live, cleared on change). Email is validated with a
  // lenient format check; password with a min length; confirm must match.
  const [emailError, setEmailError] = useState("");
  const [passwordError, setPasswordError] = useState("");
  const [confirmError, setConfirmError] = useState("");

  const clearFieldErrors = () => {
    setEmailError("");
    setPasswordError("");
    setConfirmError("");
  };

  function validate(): boolean {
    let valid = true;
    clearFieldErrors();

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) {
      setEmailError("Enter a valid email address.");
      valid = false;
    }
    if (password.length < 8) {
      setPasswordError("Password must be at least 8 characters.");
      valid = false;
    }
    if (!isLogin && confirmPassword !== password) {
      setConfirmError("Passwords do not match.");
      valid = false;
    }
    return valid;
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    if (isLoading) return;

    if (!validate()) return;

    setIsLoading(true);
    setError("");

    try {
      if (isLogin) {
        await login(email.trim(), password);
      } else {
        await signup(email.trim(), password);
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : "Request failed");
    } finally {
      setIsLoading(false);
    }
  }

  return (
    <div className="relative flex w-full max-w-sm flex-col justify-between p-6 md:p-8">
      <div
        className={cn(
          "absolute -inset-y-6 -left-px w-px bg-border",
          "dark:bg-[radial-gradient(50%_80%_at_20%_0%,--theme(--color-foreground/.1),transparent)]"
        )}
      />
      <div className="absolute -inset-y-6 -right-px w-px bg-border" />
      <div className="absolute -inset-x-6 -top-px h-px bg-border" />
      <div className="absolute -inset-x-6 -bottom-px h-px bg-border" />
      <DecorIcon position="top-left" />
      <DecorIcon position="bottom-right" />

      <div className="w-full space-y-6">
        <div className="flex flex-col space-y-1">
          <h1 className="font-bold text-2xl tracking-wide">
            {isLogin ? "Welcome Back" : "Join Now!"}
          </h1>
          <p className="text-base text-muted-foreground">
            {isLogin
              ? "Login to your account."
              : "Create your account."}
          </p>
        </div>

        <div className="space-y-4">
          <form className="space-y-2" onSubmit={handleSubmit} noValidate>
            {error && (
              <p className="text-sm text-red-500 text-center" role="alert">
                {error}
              </p>
            )}

            <InputGroup>
              <InputGroupInput
                placeholder="your.email@example.com"
                type="email"
                value={email}
                aria-invalid={emailError ? true : undefined}
                aria-describedby={emailError ? "auth-email-error" : undefined}
                onChange={(e) => {
                  setEmail(e.target.value);
                  setEmailError("");
                }}
                required
              />
              <InputGroupAddon align="inline-start">
                <AtSignIcon />
              </InputGroupAddon>
            </InputGroup>
            {emailError && (
              <p id="auth-email-error" className="text-sm text-red-500" role="alert">
                {emailError}
              </p>
            )}

            <InputGroup>
              <InputGroupInput
                placeholder="Password"
                type="password"
                value={password}
                aria-invalid={passwordError ? true : undefined}
                aria-describedby={passwordError ? "auth-password-error" : undefined}
                onChange={(e) => {
                  setPassword(e.target.value);
                  setPasswordError("");
                  setConfirmError("");
                }}
                required
              />
              <InputGroupAddon align="inline-start">
                <LockIcon />
              </InputGroupAddon>
            </InputGroup>
            {passwordError && (
              <p id="auth-password-error" className="text-sm text-red-500" role="alert">
                {passwordError}
              </p>
            )}

            {!isLogin && (
              <>
                <InputGroup>
                  <InputGroupInput
                    placeholder="Confirm password"
                    type="password"
                    value={confirmPassword}
                    aria-invalid={confirmError ? true : undefined}
                    aria-describedby={confirmError ? "auth-confirm-error" : undefined}
                    onChange={(e) => {
                      setConfirmPassword(e.target.value);
                      setConfirmError("");
                    }}
                    required
                  />
                  <InputGroupAddon align="inline-start">
                    <LockIcon />
                  </InputGroupAddon>
                </InputGroup>
                {confirmError && (
                  <p id="auth-confirm-error" className="text-sm text-red-500" role="alert">
                    {confirmError}
                  </p>
                )}
              </>
            )}

            <Button className="w-full" size="sm" type="submit" disabled={isLoading}>
              {isLoading ? (
                <>
                  <Loader2 aria-hidden className="size-4 animate-spin" />
                  {isLogin ? "Signing in..." : "Creating account..."}
                </>
              ) : isLogin ? (
                "Login with Email"
              ) : (
                "Create Account"
              )}
            </Button>
          </form>

          <button
            type="button"
            onClick={onToggleMode}
            className="w-full text-center text-sm text-muted-foreground underline underline-offset-4 hover:text-primary transition-colors"
          >
            {isLogin
              ? "Don't have an account? Sign Up"
              : "Already have an account? Login"}
          </button>

          {isLogin && (
            <Link
              href="/forgot-password"
              className="block w-full text-center text-sm text-muted-foreground underline underline-offset-4 hover:text-primary transition-colors"
            >
              Forgot your password?
            </Link>
          )}

          <AuthDivider>OR</AuthDivider>

          <GoogleSignInButton />
        </div>

        <p className="text-muted-foreground text-sm text-center">
          By clicking continue, you agree to our Terms of Service and Privacy Policy.
        </p>
      </div>
    </div>
  );
}
