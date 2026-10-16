export function watchOverflow(el: HTMLElement, onChange: (overflows: boolean) => void): () => void {
  let live = true;
  const measure = () => live && onChange(el.scrollHeight > el.clientHeight + 1);
  measure();
  const observer = new ResizeObserver(measure);
  observer.observe(el);
  window.addEventListener('resize', measure);
  void document.fonts?.ready.then(measure);
  return () => {
    live = false;
    observer.disconnect();
    window.removeEventListener('resize', measure);
  };
}
