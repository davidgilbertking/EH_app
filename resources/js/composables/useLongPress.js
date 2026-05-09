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
export function useLongPress({
    onTap,
    onLongPress,
    threshold = 600,
    moveTolerance = 28,
    touchAction = 'manipulation',
    preventDefaultOnStart = false,
}) {
    let timer = null;
    let firedLong = false;
    let startX = 0;
    let startY = 0;
    let pointerActive = false;
    let touchActive = false;
    let touchId = null;
    let suppressPointerUntilTs = 0;

    const clear = () => {
        if (timer) {
            clearTimeout(timer);
            timer = null;
        }
    };

    const startPress = (x, y, event) => {
        firedLong = false;
        startX = x ?? 0;
        startY = y ?? 0;
        clear();
        timer = setTimeout(() => {
            firedLong = true;
            timer = null;
            onLongPress?.(event);
        }, threshold);
    };

    const movePress = (x, y) => {
        if (!timer) return;
        const dx = (x ?? 0) - startX;
        const dy = (y ?? 0) - startY;
        if (Math.hypot(dx, dy) > moveTolerance) clear();
    };

    const endPress = (event) => {
        const wasTimerActive = !!timer;
        clear();
        if (firedLong) return;
        if (wasTimerActive) onTap?.(event);
    };

    const onPointerDown = (e) => {
        if (touchActive || Date.now() < suppressPointerUntilTs) return;
        if (preventDefaultOnStart && e.cancelable) e.preventDefault();
        pointerActive = true;
        startPress(e.clientX, e.clientY, e);
    };

    const onPointerMove = (e) => {
        if (!pointerActive || touchActive || Date.now() < suppressPointerUntilTs) return;
        movePress(e.clientX, e.clientY);
    };

    const onPointerUp = (e) => {
        if (!pointerActive) return;
        pointerActive = false;
        endPress(e);
    };

    const onPointerCancel = () => {
        pointerActive = false;
        clear();
    };

    const onPointerLeave = () => {
        pointerActive = false;
        clear();
    };

    const findTouch = (touchList) => {
        if (!touchList) return null;
        for (let i = 0; i < touchList.length; i += 1) {
            const t = touchList[i];
            if (touchId == null || t.identifier === touchId) return t;
        }
        return null;
    };

    const onTouchStart = (e) => {
        if (touchActive) return;
        const t = findTouch(e.changedTouches) || findTouch(e.touches);
        if (!t) return;
        if (preventDefaultOnStart && e.cancelable) e.preventDefault();
        touchActive = true;
        touchId = t.identifier;
        pointerActive = false;
        suppressPointerUntilTs = Date.now() + 700;
        startPress(t.clientX, t.clientY, e);
    };

    const onTouchMove = (e) => {
        if (!touchActive) return;
        const t = findTouch(e.changedTouches) || findTouch(e.touches);
        if (!t) return;
        if (preventDefaultOnStart && e.cancelable) e.preventDefault();
        movePress(t.clientX, t.clientY);
    };

    const onTouchEnd = (e) => {
        if (!touchActive) return;
        const t = findTouch(e.changedTouches) || findTouch(e.touches);
        if (!t) return;
        touchActive = false;
        touchId = null;
        endPress(e);
    };

    const onTouchCancel = () => {
        touchActive = false;
        touchId = null;
        clear();
    };

    // Suppress browser context menu on long press for touch + mouse.
    const onContextMenu = (e) => {
        e.preventDefault();
    };

    return {
        onPointerdown: onPointerDown,
        onTouchstart: onTouchStart,
        onTouchmove: onTouchMove,
        onTouchend: onTouchEnd,
        onTouchcancel: onTouchCancel,
        onDragstart: (e) => {
            e.preventDefault();
        },
        onPointermove: onPointerMove,
        onPointerup: onPointerUp,
        onPointercancel: onPointerCancel,
        onPointerleave: onPointerLeave,
        onContextmenu: onContextMenu,
        // Disable iOS callout on touch & long-press
        style: { WebkitTouchCallout: 'none', userSelect: 'none', touchAction },
    };
}
