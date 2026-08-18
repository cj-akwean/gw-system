import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import AuthRoute from "@/app/auth/page";
import { AUTH_NOTICE_PASSWORD_CHANGED } from "@/lib/auth-context";

const mockReplace = vi.fn();
const mockLogin = vi.fn();
const mockSignup = vi.fn();

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: mockReplace, push: vi.fn() }),
  useSearchParams: () => mockSearchParams(),
}));

vi.mock("next/link", () => ({
  default: ({ href, children }: { href: string; children: React.ReactNode }) => (
    <a href={href}>{children}</a>
  ),
}));

let mockUseAuth: () => {
  isAuthenticated: boolean;
  ready: boolean;
  user: { name: string | null; avatar_id: number | null } | null;
  login: () => Promise<void>;
  signup: () => Promise<void>;
};

vi.mock("@/lib/auth-context", () => ({
  AUTH_NOTICE_PASSWORD_CHANGED: "password_changed",
  useAuth: () => mockUseAuth(),
}));

let mockSearchParams: () => URLSearchParams;

describe("AuthRoute password-changed banner", () => {
  beforeEach(() => {
    mockReplace.mockReset();
    mockLogin.mockReset();
    mockSignup.mockReset();
    mockLogin.mockResolvedValue(undefined);
    mockSignup.mockResolvedValue(undefined);
    mockSearchParams = () => new URLSearchParams("");
    mockUseAuth = () => ({
      isAuthenticated: false,
      ready: true,
      user: null,
      login: mockLogin,
      signup: mockSignup,
    });
  });

  it("shows the banner when the notice param is password_changed", () => {
    mockSearchParams = () =>
      new URLSearchParams(`notice=${AUTH_NOTICE_PASSWORD_CHANGED}`);

    render(<AuthRoute />);

    expect(
      screen.getByText(/Password updated. Please sign in with your new password./i)
    ).toBeInTheDocument();
  });

  it("does not show the banner without the notice param", () => {
    render(<AuthRoute />);

    expect(
      screen.queryByText(/Password updated. Please sign in with your new password./i)
    ).not.toBeInTheDocument();
  });

  it("does not show the banner for other notice values", () => {
    mockSearchParams = () => new URLSearchParams("notice=something_else");

    render(<AuthRoute />);

    expect(
      screen.queryByText(/Password updated. Please sign in with your new password./i)
    ).not.toBeInTheDocument();
  });
});