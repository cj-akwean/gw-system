import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import Hero33 from "@/components/ui/hero-33";

vi.mock("@/lib/auth-context", () => ({
  useAuth: () => mockUseAuth(),
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: mockPush }),
}));

vi.mock("next/image", () => ({
  default: ({ src, alt, ...props }: { src: string; alt: string; [key: string]: unknown }) => (
    // eslint-disable-next-line @next/next/no-img-element
    <img src={src} alt={alt} {...props} />
  ),
}));

vi.mock("next/link", () => ({
  default: ({
    href,
    children,
    ...props
  }: {
    href: string;
    children: React.ReactNode;
  }) => (
    <a href={href} {...props}>
      {children}
    </a>
  ),
}));

vi.mock("@/components/fancy/physics/elastic-line", () => ({
  default: () => null,
}));

vi.mock("@/components/ui/multi-direction-slide-text", () => ({
  MultiDirectionSlideText: () => <div />,
}));

vi.mock("@/lib/loading-context", () => ({
  useLoadingComplete: () => false,
}));

vi.mock("@/lib/theme", () => ({
  useTheme: () => ({ dark: false, mounted: true, toggle: vi.fn() }),
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
    expect(screen.getByAltText("Water orb")).toHaveAttribute(
      "src",
      "/images/water-orb.webp"
    );
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

  it("hamburger holds nav items and Sign In for guests", async () => {
    mockUseAuth = () => ({
      isAuthenticated: false,
      ready: true,
      user: null,
      logout: vi.fn(),
    });

    render(
      <Hero33
        logoText="Guinobatan Waterworks"
        navItems={[
          { label: "Flights", href: "#flights" },
          { label: "Pricing", href: "#pricing" },
        ]}
      />
    );

    expect(screen.getByRole("link", { name: "Sign In" })).toHaveAttribute(
      "href",
      "/auth"
    );

    await userEvent.click(screen.getByRole("button", { name: "Open menu" }));

    expect(screen.getAllByText("Flights").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Pricing").length).toBeGreaterThan(0);
    expect(screen.getByRole("link", { name: "Sign In" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Toggle theme" })).toBeInTheDocument();
  });

  it("hides the hamburger for signed-in users", () => {
    mockUseAuth = () => ({
      isAuthenticated: true,
      ready: true,
      user: { name: "Maria", email: "maria@example.com", avatar_id: 2 },
      logout: vi.fn(),
    });

    render(<Hero33 logoText="Guinobatan Waterworks" />);

    expect(screen.queryByRole("button", { name: "Open menu" })).not.toBeInTheDocument();
  });
});
