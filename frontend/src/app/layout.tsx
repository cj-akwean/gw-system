import type { Metadata } from "next";
import "./globals.css";
import { ThemeToggle } from "@/components/theme-toggle";
import { LoadingScreen } from "@/components/loading-screen";
import { Space_Grotesk, Montserrat } from "next/font/google";
import { cn } from "@/lib/utils";

const montserratHeading = Montserrat({subsets:['latin'],variable:'--font-heading'});

const spaceGrotesk = Space_Grotesk({subsets:['latin'],variable:'--font-sans'});

export const metadata: Metadata = {
  title: "GW-System",
  description: "Guinobatan Waterworks System",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="en" suppressHydrationWarning className={cn("font-sans", spaceGrotesk.variable, montserratHeading.variable)}>
      <head>
        <script
          dangerouslySetInnerHTML={{
            __html: `
              if (localStorage.getItem('theme') === 'dark') {
                document.documentElement.classList.add('dark')
              }
            `,
          }}
        />
      </head>
      <body>
        <LoadingScreen />
        {children}
        <ThemeToggle />
      </body>
    </html>
  );
}
