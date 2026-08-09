import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ProfileDropdown } from "@/components/portal/profile-dropdown";

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

let mockPathname = "/";

vi.mock("next/navigation", () => ({
  usePathname: () => mockPathname,
}));

const mockLogout = vi.fn();

const user = {
  id: 1,
  name: "Maria",
  email: "maria@example.com",
  avatar_id: 2,
};

describe("ProfileDropdown", () => {
  beforeEach(() => {
    mockLogout.mockReset();
    mockPathname = "/";
  });

  it("shows the name, email and avatar trigger", () => {
    render(<ProfileDropdown user={user} onLogout={mockLogout} />);

    expect(screen.getByText("Maria")).toBeInTheDocument();
    expect(screen.getByText("maria@example.com")).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: "Open profile menu" })
    ).toBeInTheDocument();
  });

  it("shows the menu items when opened", async () => {
    render(<ProfileDropdown user={user} onLogout={mockLogout} />);

    await userEvent.click(screen.getByRole("button", { name: "Open profile menu" }));

    expect(screen.getByText("Profile")).toBeInTheDocument();
    expect(screen.getByText("Dashboard")).toBeInTheDocument();
    expect(screen.getByText("Settings")).toBeInTheDocument();
    expect(screen.getByText("Sign Out")).toBeInTheDocument();
    expect(screen.queryByText("Landing Page")).not.toBeInTheDocument();
  });

  it("links Settings to the settings page", async () => {
    render(<ProfileDropdown user={user} onLogout={mockLogout} />);

    await userEvent.click(screen.getByRole("button", { name: "Open profile menu" }));

    expect(screen.getByText("Settings").closest("a")).toHaveAttribute(
      "href",
      "/settings"
    );
  });

  it("hides the Dashboard item while on the dashboard page", async () => {
    mockPathname = "/dashboard";
    render(<ProfileDropdown user={user} onLogout={mockLogout} />);

    await userEvent.click(screen.getByRole("button", { name: "Open profile menu" }));

    expect(screen.queryByText("Dashboard")).not.toBeInTheDocument();
    expect(screen.getByText("Settings")).toBeInTheDocument();
  });

  it("calls onLogout when Sign Out is clicked", async () => {
    render(<ProfileDropdown user={user} onLogout={mockLogout} />);

    await userEvent.click(screen.getByRole("button", { name: "Open profile menu" }));
    await userEvent.click(screen.getByTestId("profile-sign-out"));

    expect(mockLogout).toHaveBeenCalledTimes(1);
  });

  it("falls back to a default label without a user name", () => {
    render(
      <ProfileDropdown
        user={{ id: 2, name: null, email: "jane@example.com", avatar_id: null }}
        onLogout={mockLogout}
      />
    );

    expect(screen.getByText("My Account")).toBeInTheDocument();
  });
});
