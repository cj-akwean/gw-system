"use client"

import { useEffect, useRef, useState } from "react"
import { Check, ChevronRight } from "lucide-react"

import { cn } from "@/lib/utils"

export interface SwipeButtonProps extends React.HTMLAttributes<HTMLDivElement> {
  onSwipeComplete?: () => void
  text?: string
  className?: string
  gap?: number
  validationDuration?: number
}

export function SwipeButton({
  onSwipeComplete,
  text = "Swipe to pay",
  className,
  gap = 3,
  validationDuration = 2000,
  ...props
}: SwipeButtonProps) {
  const [isSwiped, setIsSwiped] = useState(false)
  const [isValidated, setIsValidated] = useState(false)
  const [startX, setStartX] = useState(0)
  const [currentX, setCurrentX] = useState(0)
  const [isDragging, setIsDragging] = useState(false)
  const containerRef = useRef<HTMLDivElement>(null)
  const buttonRef = useRef<HTMLButtonElement>(null)

  useEffect(() => {
    if (isValidated) {
      const timer = setTimeout(() => {
        setIsValidated(false)
        setIsSwiped(false)
        setCurrentX(0)
        setIsDragging(false)
      }, validationDuration)
      return () => clearTimeout(timer)
    }
  }, [isValidated, validationDuration])

  const handleStart = (clientX: number) => {
    if (isValidated) return
    setStartX(clientX)
    setIsDragging(true)
  }

  const handleMove = (clientX: number) => {
    if (!buttonRef.current || !isDragging || isValidated) return

    const containerWidth = containerRef.current?.offsetWidth || 0
    const buttonWidth = buttonRef.current.offsetWidth
    const maxSwipe = containerWidth - buttonWidth - gap * 2

    let newX = clientX - startX
    newX = Math.max(0, Math.min(newX, maxSwipe))

    setCurrentX(newX)
    setIsSwiped(newX >= maxSwipe - 10)
  }

  const handleEnd = () => {
    if (isValidated) return

    if (isSwiped) {
      setIsValidated(true)
      setCurrentX(0)
      onSwipeComplete?.()
    } else {
      setCurrentX(0)
      setIsSwiped(false)
    }
    setIsDragging(false)
  }

  return (
    <div
      ref={containerRef}
      className={cn(
        "relative h-12 w-full overflow-hidden rounded-md",
        "border border-border bg-card shadow-sm",
        "transition-colors duration-200",
        className
      )}
      onTouchStart={(e) => handleStart(e.touches[0].clientX)}
      onTouchMove={(e) => handleMove(e.touches[0].clientX)}
      onTouchEnd={handleEnd}
      onMouseDown={(e) => handleStart(e.clientX)}
      onMouseMove={(e) => handleMove(e.clientX)}
      onMouseUp={handleEnd}
      onMouseLeave={handleEnd}
      role="button"
      aria-label={text}
      {...props}
    >
      <button
        ref={buttonRef}
        className={cn(
          "absolute rounded-md",
          "bg-neutral-900 text-white dark:bg-white dark:text-neutral-900",
          "flex items-center justify-center",
          "cursor-grab active:cursor-grabbing",
          "shadow-sm transition-all duration-300",
          "hover:bg-neutral-800 dark:hover:bg-neutral-100",
          "focus-visible:ring-2 focus-visible:ring-neutral-400 focus-visible:ring-offset-2 focus-visible:outline-none dark:focus-visible:ring-neutral-600 dark:focus-visible:ring-offset-neutral-900",
          "disabled:pointer-events-none",
          isValidated &&
            "w-[calc(100%-6px)] cursor-default bg-emerald-500 opacity-100 hover:bg-emerald-500 dark:bg-emerald-500 dark:hover:bg-emerald-500"
        )}
        style={{
          width: isValidated ? `calc(100% - ${gap * 2}px)` : "36px",
          height: `calc(100% - ${gap * 2}px)`,
          left: `${gap}px`,
          top: `${gap}px`,
          transform: isValidated ? "none" : `translateX(${currentX}px)`,
          transition: isDragging ? "none" : "all 0.3s ease",
        }}
        aria-label={isValidated ? "Validated" : text}
        disabled={isValidated}
      >
        {isValidated ? (
          <Check className="h-4 w-4" aria-hidden="true" />
        ) : (
          <ChevronRight className="h-4 w-4" aria-hidden="true" />
        )}
      </button>
      <div className="flex h-full w-full items-center justify-center">
        <span className="pointer-events-none text-sm font-medium text-muted-foreground select-none">
          {text}
        </span>
      </div>
    </div>
  )
}
