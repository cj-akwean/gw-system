import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { DashboardHeader } from "@/components/portal/dashboard-header";

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

vi.mock("@/components/portal/profile-dropdown", () => ({
  ProfileDropdown: ({ user }: { user: { name: string | null } | null }) => (
    <div data-testid="profile-dropdown">{user?.name}</div>
  ),
}));

const user = {
  id: 1,
  name: "Maria",
  email: "maria@example.com",
  avatar_id: 2,
};

describe("DashboardHeader", () => {
  it("links the brand back to the landing page with the landing-style dot", () => {
    render(<DashboardHeader user={user} onLogout={vi.fn()} />);

    const brand = screen.getByRole("link", { name: /Guinobatan Waterworks/ });
    expect(brand).toHaveAttribute("href", "/");
    expect(brand.querySelector(".text-amber-500")?.textContent).toBe(".");
  });

  it("renders the profile dropdown with the user", () => {
    render(<DashboardHeader user={user} onLogout={vi.fn()} />);

    expect(screen.getByTestId("profile-dropdown")).toHaveTextContent("Maria");
  });
});
