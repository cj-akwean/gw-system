import { describe, it, expect, vi, afterEach } from "vitest";
import { render } from "@testing-library/react";
import { GoogleSignInButton } from "@/components/google-signin-button";

vi.mock("@/lib/auth-context", () => ({
  useAuth: () => ({ loginWithGoogle: vi.fn() }),
}));

describe("GoogleSignInButton", () => {
  afterEach(() => {
    delete process.env.NEXT_PUBLIC_GOOGLE_CLIENT_ID;
  });

  it("renders nothing when no client id is configured", () => {
    delete process.env.NEXT_PUBLIC_GOOGLE_CLIENT_ID;

    const { container } = render(<GoogleSignInButton />);

    expect(container).toBeEmptyDOMElement();
  });

  it("does not attempt to load the GIS script without a client id", () => {
    delete process.env.NEXT_PUBLIC_GOOGLE_CLIENT_ID;
    const createElement = vi.spyOn(document, "createElement");

    render(<GoogleSignInButton />);

    expect(
      createElement.mock.results.some(
        (r) =>
          r.value instanceof HTMLScriptElement &&
          r.value.src.includes("accounts.google.com/gsi/client")
      )
    ).toBe(false);
    createElement.mockRestore();
  });
});