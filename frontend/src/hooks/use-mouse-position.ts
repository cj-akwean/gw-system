import { RefObject, useEffect, useRef, useState } from "react"

/**
 * Tracks the pointer position, optionally relative to a container.
 *
 * Updates are throttled to one per animation frame and identical positions
 * bail out, so a stream of mousemove events (120Hz+) never triggers more than
 * one re-render per frame — and none at all while the pointer is stationary.
 * Without this, every event created a fresh position object, which re-ran the
 * elastic-line effects below and spiralled into React's dev-mode
 * "Maximum update depth exceeded" (updates scheduled from every passive
 * effect flush).
 */
export const useMousePosition = (
  containerRef?: RefObject<HTMLElement | SVGElement | null>
) => {
  const [position, setPosition] = useState({ x: 0, y: 0 })
  const pendingRef = useRef<{ x: number; y: number } | null>(null)
  const frameRef = useRef(0)

  useEffect(() => {
    const updatePosition = (clientX: number, clientY: number) => {
      pendingRef.current = { x: clientX, y: clientY }
      if (frameRef.current) return

      frameRef.current = requestAnimationFrame(() => {
        frameRef.current = 0
        const pending = pendingRef.current
        pendingRef.current = null
        if (!pending) return

        let next: { x: number; y: number }
        if (containerRef && containerRef.current) {
          const rect = containerRef.current.getBoundingClientRect()
          // Calculate relative position even when outside the container
          next = { x: pending.x - rect.left, y: pending.y - rect.top }
        } else {
          next = { x: pending.x, y: pending.y }
        }

        setPosition((prev) =>
          prev.x === next.x && prev.y === next.y ? prev : next
        )
      })
    }

    const handleMouseMove = (ev: MouseEvent) => {
      updatePosition(ev.clientX, ev.clientY)
    }

    const handleTouchMove = (ev: TouchEvent) => {
      const touch = ev.touches[0]
      if (!touch) return
      updatePosition(touch.clientX, touch.clientY)
    }

    // Listen for both mouse and touch events
    window.addEventListener("mousemove", handleMouseMove)
    window.addEventListener("touchmove", handleTouchMove)

    return () => {
      window.removeEventListener("mousemove", handleMouseMove)
      window.removeEventListener("touchmove", handleTouchMove)
      if (frameRef.current) {
        cancelAnimationFrame(frameRef.current)
        frameRef.current = 0
      }
    }
  }, [containerRef])

  return position
}
