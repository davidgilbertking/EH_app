/**
 * useLongPress(handlers, opts) returns event-listener bindings for a button.
 *
 * Distinguishes between:
 *  - a tap (pointerdown -> pointerup before threshold)
 *  - a long press (pointerdown held > threshold ms)
 *
 * Cancels long-press if the pointer leaves or moves significantly.
 *
 * Usage in template:
 *   <button v-bind="bindings" />
 * with `bindings = useLongPress({ onTap, onLongPress })`.
 */
export function useLongPress({ onTap, onLongPress, threshold = 600, moveTolerance = 12 }) {
    let timer = null;
    let firedLong = false;
    let startX = 0;
    let startY = 0;

    const clear = () => {
        if (timer) {
            clearTimeout(timer);
            timer = null;
        }
    };

    const onPointerDown = (e) => {
        firedLong = false;
        startX = e.clientX ?? 0;
        startY = e.clientY ?? 0;
        clear();
        timer = setTimeout(() => {
            firedLong = true;
            timer = null;
            onLongPress?.(e);
        }, threshold);
    };

    const onPointerMove = (e) => {
        if (!timer) return;
        const dx = (e.clientX ?? 0) - startX;
        const dy = (e.clientY ?? 0) - startY;
        if (Math.hypot(dx, dy) > moveTolerance) clear();
    };

    const onPointerUp = (e) => {
        const wasTimerActive = !!timer;
        clear();
        if (firedLong) return;
        if (wasTimerActive) onTap?.(e);
    };

    const onPointerCancel = () => {
        clear();
    };

    const onPointerLeave = () => {
        clear();
    };

    // Suppress browser context menu on long press for touch + mouse.
    const onContextMenu = (e) => {
        e.preventDefault();
    };

    return {
        onPointerdown: onPointerDown,
        onPointermove: onPointerMove,
        onPointerup: onPointerUp,
        onPointercancel: onPointerCancel,
        onPointerleave: onPointerLeave,
        onContextmenu: onContextMenu,
        // Disable iOS callout on touch & long-press
        style: { WebkitTouchCallout: 'none', userSelect: 'none' },
    };
}
