'use client';

import { motion, type Variants } from 'motion/react';
import { Armchair, Menu, Monitor, PlaneTakeoff } from 'lucide-react';
import Image from 'next/image';
import ElasticLine from '@/components/fancy/physics/elastic-line';
import { ThemeToggle } from '@/components/theme-toggle';
import { WaterCanvas } from '@/components/water-button';
import { MultiDirectionSlideText } from '@/components/ui/multi-direction-slide-text';
import { useLoadingComplete } from '@/lib/loading-context';
import { ProfileDropdown } from '@/components/portal/profile-dropdown';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAuth } from '@/lib/auth-context';
import { useRouter } from 'next/navigation';
import React from 'react';

export interface Hero33Props {
    logoText?: string;
    navItems?: string[];
    primaryActionText?: string;
    secondaryActionText?: string;
    titleLines?: string[];
    description?: string;
    features?: {
        icon: React.ElementType;
        title: string;
        description: string;
    }[];
    backgroundImage?: string;
}

export default function Hero33({
  logoText = 'Watermelon',
  navItems = ['Flights', 'Destinations', 'Pricing', 'Contact'],
  primaryActionText = 'Explore Flights',
  secondaryActionText = 'Learn More',
  titleLines = ['Peak Moments,', 'Unforgettable', 'Journeys.'],
  description = '',
  features = [
    {
      icon: Armchair,
      title: 'Premium Comfort',
      description: 'Relax in spacious, luxurious\nseating',
    },
    {
      icon: Monitor,
      title: 'Stunning Views',
      description: 'Marvel at the world from\nnew heights',
    },
  ],
  backgroundImage = 'https://assets.watermelon.sh/hero-33-bg.avif',
}: Hero33Props) {
  const loadingComplete = useLoadingComplete();
  const { isAuthenticated, ready, user, logout } = useAuth();
  const router = useRouter();

  const handleLogout = async () => {
    await logout();
    router.push('/');
  };

  const navVariants: Variants = {
    hidden: { opacity: 0, y: -16, filter: 'blur(6px)' },
    visible: {
      opacity: 1,
      y: 0,
      filter: 'blur(0px)',
      transition: {
        type: 'spring',
        damping: 20,
        stiffness: 150,
        duration: 0.5,
      },
    },
  };

  // CTA buttons: fade up after title lines settle
  const ctaContainerVariants: Variants = {
    hidden: { opacity: 0 },
    visible: {
      opacity: 1,
      transition: { staggerChildren: 0.1, delayChildren: 1.4 },
    },
  };
  const ctaItemVariants: Variants = {
    hidden: { opacity: 0, y: 12, scale: 0.96 },
    visible: {
      opacity: 1,
      y: 0,
      scale: 1,
      transition: { type: 'spring', damping: 18, stiffness: 150 },
    },
  };

  // Bottom feature cards: slide up with stagger
  const featuresContainerVariants: Variants = {
    hidden: { opacity: 0 },
    visible: {
      opacity: 1,
      transition: { staggerChildren: 0.12, delayChildren: 1.1 },
    },
  };
  const featureItemVariants: Variants = {
    hidden: { opacity: 0, y: 20, filter: 'blur(4px)', scale: 0.97 },
    visible: {
      opacity: 1,
      y: 0,
      filter: 'blur(0px)',
      scale: 1,
      transition: { type: 'spring', damping: 22, stiffness: 120 },
    },
  };

  const signInLink = (
    <a
      href="/auth"
      className="hidden rounded-md border border-amber-500 px-6 py-2.5 text-sm font-medium text-neutral-900 transition-transform hover:bg-black/5 active:scale-[0.96] lg:inline-block dark:text-white dark:hover:bg-white/10"
    >
      Sign In
    </a>
  );

  return (
    <div className="relative min-h-screen w-full overflow-hidden font-sans antialiased selection:bg-black/10 dark:selection:bg-white/20">
      {/* Content Container */}
      <div className="relative z-10 flex min-h-screen flex-col px-6 pt-6 pb-12 md:px-12 lg:px-20">
        <div className="mx-auto flex w-full max-w-7xl flex-1 flex-col">
          {/* Navigation */}
          <motion.nav
            variants={navVariants}
            initial="hidden"
            animate="visible"
            className="flex items-center justify-between"
          >
            <div className="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">
              {logoText}
              <span className="text-amber-500">.</span>
            </div>
            <div className="hidden items-center gap-10 lg:flex">
              {navItems.map((item) => (
                <a
                  key={item}
                  href="#"
                  className="text-sm font-medium text-neutral-700/90 transition-colors hover:text-neutral-900 dark:text-white/90 dark:hover:text-white"
                >
                  {item}
                </a>
              ))}
            </div>
            {!ready ? (
              <div
                aria-hidden
                className="inline-block h-10 w-24 rounded-md border border-amber-500/40"
              />
            ) : isAuthenticated ? (
              <ProfileDropdown user={user} onLogout={handleLogout} />
            ) : (
              <div className="flex items-center gap-3">
                <ThemeToggle className="hidden lg:inline-flex" />
                <div className="lg:hidden">
                  <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                      <button
                        aria-label="Open menu"
                        type="button"
                        className="flex h-11 w-11 cursor-pointer items-center justify-center rounded-full border border-amber-500/60 text-neutral-900 transition-transform hover:bg-black/5 active:scale-[0.96] dark:text-white dark:hover:bg-white/10"
                      >
                        <Menu className="h-5 w-5" />
                      </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                      align="end"
                      sideOffset={8}
                      className="w-56 origin-top-right rounded-2xl border-border bg-background/95 p-2 shadow-xl backdrop-blur-sm"
                    >
                      <div className="space-y-1">
                        {navItems.map((item) => (
                          <DropdownMenuItem asChild key={item}>
                            <a href="#">{item}</a>
                          </DropdownMenuItem>
                        ))}
                      </div>
                      <DropdownMenuSeparator className="my-2 bg-gradient-to-r from-transparent via-border to-transparent" />
                      <div className="flex justify-center p-1">
                        <WaterCanvas rounded={6} waterAmount={50}>
                          <a
                            href="/auth"
                            className="flex items-center justify-center rounded-md border border-amber-500 px-6 py-2.5 text-sm font-medium text-neutral-900 transition-transform hover:bg-black/5 active:scale-[0.96] dark:text-white dark:hover:bg-white/10"
                          >
                            Sign In
                          </a>
                        </WaterCanvas>
                      </div>
                      <div className="flex items-center justify-between px-2 py-1.5">
                        <span className="text-sm text-muted-foreground">Theme</span>
                        <ThemeToggle className="h-9 w-9" />
                      </div>
                    </DropdownMenuContent>
                  </DropdownMenu>
                </div>
                <WaterCanvas waterAmount={50} frozen={!loadingComplete}>{signInLink}</WaterCanvas>
              </div>
            )}
          </motion.nav>

          {/* Hero Main Content */}
          <div className="grid flex-1 grid-cols-1 place-items-center lg:grid-cols-2 lg:gap-16">
            <div className="relative order-2 z-10 flex w-full flex-col items-start lg:order-none">
              {/* Title — multi-direction slide from left/right */}
              <div style={{ position: 'relative', zIndex: 10 }}>
                <MultiDirectionSlideText
                  textLeft={titleLines[0] ?? ''}
                  textRight={titleLines[1] ?? ''}
                  shouldAnimate={loadingComplete}
                  className="text-left"
                />
              </div>

              {/* Elastic line — zero-height wrapper so it sits between h1 and description without taking space; mounts only after the loader finishes so startup rAF loops don't compete on phones */}
              {loadingComplete && (
                <div className="relative w-full overflow-visible" style={{ height: 0 }}>
                  <div className="pointer-events-none absolute left-0 z-0 w-[380px] -translate-y-1/2 text-neutral-500 dark:text-neutral-400" style={{ height: 60 }}>
                    <ElasticLine
                      grabThreshold={20}
                      releaseThreshold={50}
                      strokeWidth={1}
                      transition={{
                        type: "spring",
                        stiffness: 400,
                        damping: 5,
                      }}
                      animateInTransition={{
                        type: "spring",
                        stiffness: 300,
                        damping: 30,
                        delay: 0.15,
                      }}
                    />
                  </div>
                </div>
              )}

              {description && (
                <motion.p
                  style={{ position: 'relative', zIndex: 10 }}
                  initial={{ opacity: 0, y: 16 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ type: 'spring', stiffness: 150, damping: 22, delay: 1.2 }}
                  className="mt-6 max-w-lg text-base leading-relaxed text-neutral-600 dark:text-white/70"
                >
                  {description}
                </motion.p>
              )}

              {/* CTA — staggered scale-in after title */}
              <motion.div
                style={{ position: 'relative', zIndex: 10 }}
                variants={ctaContainerVariants}
                initial="hidden"
                animate="visible"
                className="mt-10 flex items-center gap-4"
              >
                <motion.button
                  variants={ctaItemVariants}
                  className="flex h-14 items-center gap-2 rounded-md bg-white pr-6 pl-8 text-base font-semibold text-black transition-transform hover:bg-white/90 active:scale-[0.96]"
                >
                  {primaryActionText}
                  <PlaneTakeoff className="h-5 w-5" />
                </motion.button>
                <motion.button
                  variants={ctaItemVariants}
                  className="flex h-14 items-center rounded-md border border-amber-500 px-10 text-base font-medium text-neutral-900 transition-transform hover:bg-black/5 active:scale-[0.96] dark:text-white dark:hover:bg-white/10"
                >
                  {secondaryActionText}
                </motion.button>
              </motion.div>
            </div>

            {/* Right: Water Orb Image */}
            <motion.div
              initial={{ opacity: 0, scale: 0.9 }}
              animate={{ opacity: 1, scale: 1 }}
              transition={{ type: 'spring', stiffness: 100, damping: 20, delay: 1.2 }}
              className="order-1 z-10 mt-10 flex items-center justify-center lg:order-none lg:mt-0"
            >
              <Image
                src="/images/water-orb.webp"
                alt="Water orb"
                width={1058}
                height={908}
                priority
                className="w-[490px] sm:w-96 sm:h-96 lg:w-[40rem] lg:h-[40rem] object-contain"
              />
            </motion.div>
          </div>

          {/* Bottom Features — stagger in last — hidden for now, re-enable by flipping to true */}
          {false && (
            <motion.div
              variants={featuresContainerVariants}
              initial="hidden"
              animate="visible"
              className="mt-12 flex flex-col gap-8 md:flex-row md:gap-16"
            >
              {features.map((feature, index) => {
                const Icon = feature.icon;
                return (
                  <motion.div
                    key={index}
                    variants={featureItemVariants}
                    className="flex items-center gap-4"
                  >
                    <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl border border-amber-500 bg-black/5 backdrop-blur-sm dark:bg-white/5">
                      <Icon className="h-6 w-6 text-neutral-900 dark:text-white" strokeWidth={1.5} />
                    </div>
                    <div>
                      <h3 className="text-base font-semibold text-neutral-900 dark:text-white">
                        {feature.title}
                      </h3>
                      <p className="mt-1 text-sm leading-relaxed whitespace-pre-line text-neutral-600 dark:text-white/60">
                        {feature.description}
                      </p>
                    </div>
                  </motion.div>
                );
              })}
            </motion.div>
          )}
        </div>
      </div>
    </div>
  );
}
