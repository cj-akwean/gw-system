import { useEffect, useState } from "react"

import { useDimensions } from "@/hooks/use-dimensions"
import { useMousePosition } from "@/hooks/use-mouse-position"

interface ElasticLineEvents {
  isGrabbed: boolean
  controlPoint: { x: number; y: number }
}

/**
 * State updates here use functional form with same-value bailouts, and the
 * effect depends only on primitive values — so a re-render never re-runs the
 * effect with a fresh object (the previous version created a new controlPoint
 * on every run and fed React's passive-effect update loop).
 */
export function useElasticLineEvents(
  containerRef: React.RefObject<SVGSVGElement | null>,
  isVertical: boolean,
  grabThreshold: number,
  releaseThreshold: number
): ElasticLineEvents {
  const mousePosition = useMousePosition(containerRef)
  const dimensions = useDimensions(containerRef)
  const [isGrabbed, setIsGrabbed] = useState(false)
  const [controlPoint, setControlPoint] = useState({
    x: dimensions.width / 2,
    y: dimensions.height / 2,
  })

  const { width, height } = dimensions
  const { x, y } = mousePosition

  useEffect(() => {
    if (!containerRef.current) return

    // Check if mouse is outside container bounds
    const isOutsideBounds = x < 0 || x > width || y < 0 || y > height

    if (isOutsideBounds) {
      setIsGrabbed((prev) => (prev ? false : prev))
      return
    }

    let distance: number
    let newControlPoint: { x: number; y: number }

    if (isVertical) {
      const midX = width / 2
      distance = Math.abs(x - midX)
      newControlPoint = {
        x: midX + 2.2 * (x - midX),
        y: y,
      }
    } else {
      const midY = height / 2
      distance = Math.abs(y - midY)
      newControlPoint = {
        x: x,
        y: midY + 2.2 * (y - midY),
      }
    }

    setControlPoint((prev) =>
      prev.x === newControlPoint.x && prev.y === newControlPoint.y
        ? prev
        : newControlPoint
    )

    setIsGrabbed((prev) => {
      if (!prev && distance < grabThreshold) return true
      if (prev && distance > releaseThreshold) return false
      return prev
    })
  }, [x, y, width, height, isVertical, grabThreshold, releaseThreshold])

  return { isGrabbed, controlPoint }
}
