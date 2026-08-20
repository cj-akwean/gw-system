import type { Metadata } from "next";
import Script from "next/script";
import "./globals.css";
import { Space_Grotesk, Montserrat } from "next/font/google";
import { cn } from "@/lib/utils";
import { Providers } from "@/lib/providers";
import { GWTFooter } from "@/components/gwt-footer";

const montserratHeading = Montserrat({subsets:['latin'],variable:'--font-heading'});

const spaceGrotesk = Space_Grotesk({subsets:['latin'],variable:'--font-sans'});

export const metadata: Metadata = {
  title: "Guinobatan Waterworks",
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
        <Script id="theme-init" strategy="beforeInteractive">
          {`if(localStorage.getItem('theme')==='dark')document.documentElement.classList.add('dark')`}
        </Script>
      </head>
      <body>
        <Providers>{children}</Providers>
        <GWTFooter />
      </body>
    </html>
  );
}
