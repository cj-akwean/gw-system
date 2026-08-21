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
import { AtSignIcon, LockIcon } from "lucide-react";
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
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState("");

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    if (isLoading) return;

    setIsLoading(true);
    setError("");

    try {
      if (isLogin) {
        await login(email, password);
      } else {
        await signup(email, password);
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
          <form className="space-y-2" onSubmit={handleSubmit}>
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
                onChange={(e) => setEmail(e.target.value)}
                required
              />
              <InputGroupAddon align="inline-start">
                <AtSignIcon />
              </InputGroupAddon>
            </InputGroup>

            <InputGroup>
              <InputGroupInput
                placeholder="Password"
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
              />
              <InputGroupAddon align="inline-start">
                <LockIcon />
              </InputGroupAddon>
            </InputGroup>

            <Button className="w-full" size="sm" type="submit" disabled={isLoading}>
              {isLoading ? "Signing in..." : isLogin ? "Login with Email" : "Create Account"}
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
          By clicking continue, you agree to our{" "}
          <a
            className="underline underline-offset-4 hover:text-primary"
            href="#"
          >
            Terms of Service
          </a>{" "}
          and{" "}
          <a
            className="underline underline-offset-4 hover:text-primary"
            href="#"
          >
            Privacy Policy
          </a>
          .
        </p>
      </div>
    </div>
  );
}
