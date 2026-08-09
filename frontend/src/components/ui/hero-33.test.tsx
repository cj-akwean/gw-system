import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import Hero33 from "@/components/ui/hero-33";

vi.mock("@/lib/auth-context", () => ({
  useAuth: () => mockUseAuth(),
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: mockPush }),
}));

vi.mock("@/components/fancy/physics/elastic-line", () => ({
  default: () => null,
}));

vi.mock("@/components/water-button", () => ({
  WaterCanvas: ({ children }: { children: React.ReactNode }) => (
    <>{children}</>
  ),
}));

vi.mock("@/components/ui/multi-direction-slide-text", () => ({
  MultiDirectionSlideText: () => <div />,
}));

vi.mock("@/lib/loading-context", () => ({
  useLoadingComplete: () => false,
}));

vi.mock("@/components/portal/profile-dropdown", () => ({
  ProfileDropdown: ({ user }: { user: { name: string | null } | null }) => (
    <div data-testid="profile-dropdown">{user?.name}</div>
  ),
}));

const mockPush = vi.fn();

let mockUseAuth: () => {
  isAuthenticated: boolean;
  ready: boolean;
  user: { name: string | null; email: string; avatar_id: number | null } | null;
  logout: () => Promise<void>;
};

describe("Hero33 nav auth state", () => {
  beforeEach(() => {
    mockPush.mockReset();
  });

  it("shows Sign In for guests", () => {
    mockUseAuth = () => ({
      isAuthenticated: false,
      ready: true,
      user: null,
      logout: vi.fn(),
    });

    render(<Hero33 logoText="Guinobatan Waterworks" />);

    expect(screen.getByRole("link", { name: "Sign In" })).toHaveAttribute(
      "href",
      "/auth"
    );
    expect(screen.queryByTestId("profile-dropdown")).not.toBeInTheDocument();
  });

  it("shows the profile dropdown for signed-in users", () => {
    mockUseAuth = () => ({
      isAuthenticated: true,
      ready: true,
      user: { name: "Maria", email: "maria@example.com", avatar_id: 2 },
      logout: vi.fn(),
    });

    render(<Hero33 logoText="Guinobatan Waterworks" />);

    expect(screen.queryByRole("link", { name: "Sign In" })).not.toBeInTheDocument();
    expect(screen.getByTestId("profile-dropdown")).toHaveTextContent("Maria");
  });

  it("shows neither button while auth state is loading", () => {
    mockUseAuth = () => ({
      isAuthenticated: true,
      ready: false,
      user: null,
      logout: vi.fn(),
    });

    render(<Hero33 logoText="Guinobatan Waterworks" />);

    expect(screen.queryByRole("link", { name: "Sign In" })).not.toBeInTheDocument();
    expect(screen.queryByTestId("profile-dropdown")).not.toBeInTheDocument();
  });
});
