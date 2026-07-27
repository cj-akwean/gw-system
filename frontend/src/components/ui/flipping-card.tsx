import React from "react"

import { cn } from "@/lib/utils"

interface FlippingCardProps {
  className?: string
  height?: number
  width?: number
  isFlipped?: boolean
  frontContent?: React.ReactNode
  backContent?: React.ReactNode
}

export function FlippingCard({
  className,
  frontContent,
  backContent,
  height = 300,
  width = 350,
  isFlipped = false,
}: FlippingCardProps) {
  return (
    <div
      className="[perspective:1000px]"
      style={
        {
          "--height": `${height}px`,
          "--width": `${width}px`,
        } as React.CSSProperties
      }
    >
      <div
        className={cn(
          "relative rounded-xl border border-neutral-200 bg-white shadow-lg transition-all duration-700 [transform-style:preserve-3d] dark:border-neutral-800 dark:bg-neutral-950",
          "h-[var(--height)] w-[var(--width)]",
          isFlipped && "[transform:rotateY(180deg)]",
          className
        )}
      >
        {/* Front Face */}
        <div className="absolute inset-0 h-full w-full [transform:rotateY(0deg)] rounded-[inherit] bg-white text-neutral-950 [backface-visibility:hidden] [transform-style:preserve-3d] dark:bg-zinc-950 dark:text-neutral-50">
          <div className="flex h-full w-full items-center justify-center p-2">
            {frontContent}
          </div>
        </div>
        {/* Back Face */}
        <div className="absolute inset-0 h-full w-full [transform:rotateY(180deg)] rounded-[inherit] bg-white text-neutral-950 [backface-visibility:hidden] [transform-style:preserve-3d] dark:bg-zinc-950 dark:text-neutral-50">
          <div className="flex h-full w-full items-center justify-center p-2">
            {backContent}
          </div>
        </div>
      </div>
    </div>
  )
}
