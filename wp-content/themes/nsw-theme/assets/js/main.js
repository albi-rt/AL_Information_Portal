/* NSW Albania — front-end JS. No frameworks. */

(function () {
	"use strict";

	var NSW_THEME_DATA = typeof window.NSW_THEME === "object" && window.NSW_THEME ? window.NSW_THEME : {};
	var I18N = (NSW_THEME_DATA && NSW_THEME_DATA.i18n) || {};
	var REST_URL = NSW_THEME_DATA.restUrl || "";
	var REST_NONCE = NSW_THEME_DATA.nonce || "";

	function ready(fn) {
		if (document.readyState === "loading") {
			document.addEventListener("DOMContentLoaded", fn);
		} else {
			fn();
		}
	}

	function initHeaderScroll() {
		var header = document.getElementById("site-header");
		if (!header) return;
		function update() {
			header.setAttribute("data-scrolled", window.scrollY > 10 ? "true" : "false");
		}
		update();
		window.addEventListener("scroll", update, { passive: true });
	}

	function closeDropdown(dd) {
		dd.classList.remove("is-open");
		var trigger = dd.querySelector("[data-dropdown-trigger]");
		if (trigger) trigger.setAttribute("aria-expanded", "false");
	}

	function initDropdowns() {
		var dropdowns = document.querySelectorAll("[data-dropdown]");
		if (!dropdowns.length) return;
		dropdowns.forEach(function (dd, idx) {
			var trigger = dd.querySelector("[data-dropdown-trigger]");
			var menu = dd.querySelector("[data-dropdown-menu]");
			if (!trigger || !menu) return;
			var menuId = "nsw-theme-menu-" + idx;
			menu.id = menuId;
			trigger.setAttribute("aria-haspopup", "menu");
			trigger.setAttribute("aria-controls", menuId);
			trigger.setAttribute("aria-expanded", "false");
			trigger.addEventListener("click", function (e) {
				e.preventDefault();
				e.stopPropagation();
				dropdowns.forEach(function (other) { if (other !== dd) closeDropdown(other); });
				var open = dd.classList.toggle("is-open");
				trigger.setAttribute("aria-expanded", open ? "true" : "false");
			});
		});
		document.addEventListener("click", function (e) {
			dropdowns.forEach(function (dd) { if (!dd.contains(e.target)) closeDropdown(dd); });
		});
		document.addEventListener("keydown", function (e) {
			if (e.key !== "Escape") return;
			dropdowns.forEach(function (dd) {
				if (dd.classList.contains("is-open")) {
					closeDropdown(dd);
					var t = dd.querySelector("[data-dropdown-trigger]");
					if (t) t.focus();
				}
			});
		});
	}

	function initMobileNav() {
		var toggle = document.querySelector("[data-mobile-toggle]");
		var nav = document.getElementById("mobile-nav");
		if (!toggle || !nav) return;
		var openLabel = toggle.getAttribute("aria-label") || "Menu";
		var closeLabel = toggle.getAttribute("data-close-label") || openLabel;
		var lastFocused = null;

		function openNav() {
			lastFocused = document.activeElement;
			nav.removeAttribute("hidden");
			toggle.setAttribute("aria-expanded", "true");
			toggle.setAttribute("aria-label", closeLabel);
			document.documentElement.style.overflow = "hidden";
			var firstLink = nav.querySelector("a, button");
			if (firstLink) firstLink.focus();
		}
		function closeNav() {
			nav.setAttribute("hidden", "");
			toggle.setAttribute("aria-expanded", "false");
			toggle.setAttribute("aria-label", openLabel);
			document.documentElement.style.overflow = "";
			if (lastFocused && typeof lastFocused.focus === "function") {
				lastFocused.focus();
			}
		}

		toggle.addEventListener("click", function () {
			if (nav.hasAttribute("hidden")) openNav();
			else closeNav();
		});
		nav.addEventListener("click", function (e) {
			if (e.target && e.target.tagName === "A") closeNav();
		});
		document.addEventListener("keydown", function (e) {
			if (e.key === "Escape" && !nav.hasAttribute("hidden")) closeNav();
		});
		var mq = window.matchMedia("(min-width: 768px)");
		(mq.addEventListener ? mq.addEventListener.bind(mq) : mq.addListener.bind(mq))("change", function (ev) {
			if (ev.matches && !nav.hasAttribute("hidden")) closeNav();
		});
	}

	function initReveal() {
		var elements = document.querySelectorAll("[data-reveal]");
		if (!elements.length) return;
		if (!("IntersectionObserver" in window)) {
			elements.forEach(function (el) { el.classList.add("is-visible"); });
			return;
		}
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (entry.isIntersecting) {
					entry.target.classList.add("is-visible");
					io.unobserve(entry.target);
				}
			});
		}, { threshold: 0.12, rootMargin: "0px 0px -10% 0px" });
		elements.forEach(function (el) { io.observe(el); });
	}

	function initStatsCounter() {
		var nodes = document.querySelectorAll("[data-stat-target]");
		if (!nodes.length) return;
		var reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
		if (reduce || !("IntersectionObserver" in window)) {
			nodes.forEach(function (n) {
				n.textContent = (n.getAttribute("data-stat-target") || "0") + (n.getAttribute("data-stat-suffix") || "");
			});
			return;
		}
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (!entry.isIntersecting) return;
				var el = entry.target;
				var target = parseInt(el.getAttribute("data-stat-target") || "0", 10);
				var suffix = el.getAttribute("data-stat-suffix") || "";
				var start = 0;
				var duration = 1100;
				var t0 = performance.now();
				function tick(now) {
					var p = Math.min((now - t0) / duration, 1);
					var v = Math.round(start + (target - start) * (1 - Math.pow(1 - p, 3)));
					el.textContent = v + suffix;
					if (p < 1) requestAnimationFrame(tick);
				}
				requestAnimationFrame(tick);
				io.unobserve(el);
			});
		}, { threshold: 0.4 });
		nodes.forEach(function (n) { io.observe(n); });
	}

	function initFaqFilter() {
		var container = document.querySelector("[data-faq]");
		if (!container) return;
		var pills = container.querySelectorAll("[data-faq-pill]");
		var items = container.querySelectorAll("[data-faq-item]");
		var groups = container.querySelectorAll("[data-faq-group]");
		var search = container.querySelector("[data-faq-search]");
		var empty = container.querySelector("[data-faq-empty]");
		var active = "all";

		function applyFilter() {
			var q = (search && search.value ? search.value : "").trim().toLowerCase();
			var anyVisible = false;
			items.forEach(function (item) {
				var cat = item.getAttribute("data-faq-cat") || "";
				var text = item.textContent.toLowerCase();
				var catMatch = active === "all" || cat === active;
				var textMatch = !q || text.indexOf(q) !== -1;
				var visible = catMatch && textMatch;
				item.style.display = visible ? "" : "none";
				if (visible) {
					item.removeAttribute("aria-hidden");
					anyVisible = true;
				} else {
					item.setAttribute("aria-hidden", "true");
				}
			});
			groups.forEach(function (group) {
				var visible = false;
				group.querySelectorAll("[data-faq-item]").forEach(function (it) {
					if (it.style.display !== "none") visible = true;
				});
				group.style.display = visible ? "" : "none";
			});
			if (empty) {
				if (anyVisible) empty.setAttribute("hidden", "");
				else empty.removeAttribute("hidden");
			}
		}

		pills.forEach(function (pill) {
			pill.addEventListener("click", function () {
				var value = pill.getAttribute("data-faq-pill") || "all";
				if (active === value && value !== "all") active = "all";
				else active = value;
				pills.forEach(function (p) {
					var on = p.getAttribute("data-faq-pill") === active || (active === "all" && p.getAttribute("data-faq-pill") === "all");
					p.classList.toggle("is-active", on);
					p.setAttribute("aria-pressed", on ? "true" : "false");
				});
				applyFilter();
			});
		});
		if (search) {
			search.addEventListener("input", applyFilter);
		}
	}

	function initNewsFilter() {
		var container = document.querySelector("[data-news-filter]");
		if (!container) return;
		var pills = container.querySelectorAll("[data-news-pill]");
		var grid = document.querySelector("[data-news-grid]");
		if (!grid) return;
		var cards = grid.querySelectorAll("[data-news-cat]");
		var empty = document.querySelector("[data-news-empty]");
		var active = "all";
		pills.forEach(function (pill) {
			pill.addEventListener("click", function () {
				var value = pill.getAttribute("data-news-pill") || "all";
				if (active === value && value !== "all") active = "all";
				else active = value;
				pills.forEach(function (p) {
					var on = p.getAttribute("data-news-pill") === active;
					p.classList.toggle("is-active", on);
					p.setAttribute("aria-pressed", on ? "true" : "false");
				});
				var anyVisible = false;
				cards.forEach(function (c) {
					var cat = c.getAttribute("data-news-cat") || "";
					var visible = active === "all" || cat === active;
					c.style.display = visible ? "" : "none";
					if (visible) {
						c.removeAttribute("aria-hidden");
						anyVisible = true;
					} else {
						c.setAttribute("aria-hidden", "true");
					}
				});
				if (empty) {
					if (anyVisible) empty.setAttribute("hidden", "");
					else empty.removeAttribute("hidden");
				}
			});
		});
	}

	function initServicesWizard() {
		var container = document.querySelector("[data-services-wizard]");
		if (!container) return;
		var pills = container.querySelectorAll("[data-service-pill]");
		var items = container.querySelectorAll("[data-service-item]");
		var empty = container.querySelector("[data-services-empty]");
		var agencyInput = container.querySelector(".services-wizard__control--agency [data-select-input]");
		var type = "all";
		var agency = "";

		function applyFilter() {
			var anyVisible = false;
			items.forEach(function (item) {
				var cardTypes = (item.getAttribute("data-service-types") || "").split(/\s+/);
				var cardAgency = item.getAttribute("data-service-agency") || "";
				var typeMatch = type === "all" || cardTypes.indexOf(type) !== -1;
				var agencyMatch = agency === "" || cardAgency === agency;
				var visible = typeMatch && agencyMatch;
				item.style.display = visible ? "" : "none";
				if (visible) {
					item.removeAttribute("aria-hidden");
					anyVisible = true;
				} else {
					item.setAttribute("aria-hidden", "true");
				}
			});
			if (empty) {
				if (anyVisible) empty.setAttribute("hidden", "");
				else empty.removeAttribute("hidden");
			}
		}

		pills.forEach(function (pill) {
			pill.addEventListener("click", function () {
				var value = pill.getAttribute("data-service-pill") || "all";
				if (type === value && value !== "all") type = "all";
				else type = value;
				pills.forEach(function (p) {
					var on = p.getAttribute("data-service-pill") === type || (type === "all" && p.getAttribute("data-service-pill") === "all");
					p.classList.toggle("is-active", on);
					p.setAttribute("aria-pressed", on ? "true" : "false");
				});
				applyFilter();
			});
		});

		if (agencyInput) {
			agencyInput.addEventListener("change", function () {
				agency = agencyInput.value || "";
				applyFilter();
			});
		}

		applyFilter();
	}

	function initSelects(root) {
		var scope = root || document;
		var selects = scope.querySelectorAll("[data-select]");
		if (!selects.length) return;

		selects.forEach(function (select) {
			if (select.dataset.selectReady === "1") return;
			select.dataset.selectReady = "1";

			var trigger = select.querySelector("[data-select-trigger]");
			var panel = select.querySelector("[data-select-panel]");
			var valueDisplay = select.querySelector("[data-select-value]");
			var input = select.querySelector("[data-select-input]");
			if (!trigger || !panel || !valueDisplay || !input) return;

			var options = Array.prototype.slice.call(panel.querySelectorAll("[data-select-option]"));
			var placeholder = valueDisplay.getAttribute("data-placeholder") || valueDisplay.textContent;
			var focusedIdx = -1;
			var typeahead = "";
			var typeaheadTimer = null;

			function setFocused(idx) {
				focusedIdx = idx;
				options.forEach(function (o, i) {
					o.classList.toggle("is-focused", i === idx);
					if (i === idx) {
						o.scrollIntoView({ block: "nearest" });
						trigger.setAttribute("aria-activedescendant", o.id || "");
					}
				});
			}

			function open() {
				if (!panel.hasAttribute("hidden")) return;
				panel.removeAttribute("hidden");
				trigger.setAttribute("aria-expanded", "true");
				var selectedIdx = options.findIndex(function (o) {
					return o.getAttribute("aria-selected") === "true";
				});
				setFocused(selectedIdx >= 0 ? selectedIdx : 0);
			}

			function close() {
				if (panel.hasAttribute("hidden")) return;
				panel.setAttribute("hidden", "");
				trigger.setAttribute("aria-expanded", "false");
				focusedIdx = -1;
				options.forEach(function (o) { o.classList.remove("is-focused"); });
				trigger.removeAttribute("aria-activedescendant");
			}

			function selectOption(option) {
				if (!option) return;
				var value = option.getAttribute("data-value") || "";
				var label = option.textContent.trim();
				input.value = value;
				valueDisplay.textContent = label;
				trigger.setAttribute("data-has-value", "true");
				options.forEach(function (o) {
					o.setAttribute("aria-selected", o === option ? "true" : "false");
				});
				input.dispatchEvent(new Event("change", { bubbles: true }));
				close();
				trigger.focus();
			}

			function resetSelect() {
				input.value = "";
				valueDisplay.textContent = placeholder;
				trigger.removeAttribute("data-has-value");
				options.forEach(function (o) { o.setAttribute("aria-selected", "false"); });
			}
			select._reset = resetSelect;

			trigger.addEventListener("click", function (e) {
				e.preventDefault();
				if (panel.hasAttribute("hidden")) open();
				else close();
			});

			options.forEach(function (option, i) {
				option.addEventListener("click", function () { selectOption(option); });
				option.addEventListener("mouseenter", function () { setFocused(i); });
			});

			trigger.addEventListener("keydown", function (e) {
				var open_ = !panel.hasAttribute("hidden");
				switch (e.key) {
					case "ArrowDown":
						e.preventDefault();
						if (!open_) { open(); return; }
						setFocused(Math.min(focusedIdx + 1, options.length - 1));
						break;
					case "ArrowUp":
						e.preventDefault();
						if (!open_) { open(); return; }
						setFocused(Math.max(focusedIdx - 1, 0));
						break;
					case "Home":
						if (open_) { e.preventDefault(); setFocused(0); }
						break;
					case "End":
						if (open_) { e.preventDefault(); setFocused(options.length - 1); }
						break;
					case "Enter":
					case " ":
						e.preventDefault();
						if (!open_) { open(); return; }
						if (focusedIdx >= 0) selectOption(options[focusedIdx]);
						break;
					case "Escape":
						if (open_) { e.preventDefault(); close(); }
						break;
					case "Tab":
						close();
						break;
					default:
						// Type-to-search: jump to first option starting with typed chars
						if (e.key.length === 1 && /\S/.test(e.key)) {
							if (!open_) open();
							typeahead += e.key.toLowerCase();
							clearTimeout(typeaheadTimer);
							typeaheadTimer = setTimeout(function () { typeahead = ""; }, 500);
							var match = options.findIndex(function (o) {
								return o.textContent.trim().toLowerCase().indexOf(typeahead) === 0;
							});
							if (match >= 0) setFocused(match);
						}
				}
			});

			document.addEventListener("click", function (e) {
				if (!select.contains(e.target)) close();
			});
		});
	}

	function initContactForm() {
		var form = document.querySelector("[data-contact-form]");
		if (!form) return;
		var feedback = form.querySelector("[data-contact-feedback]");
		var submit = form.querySelector("button[type=submit]");
		if (!feedback || !submit) return;
		var labelSpan = submit.querySelector("[data-submit-label]");
		var defaultLabel = labelSpan ? labelSpan.textContent : "";

		form.addEventListener("submit", function (e) {
			e.preventDefault();
			feedback.textContent = "";
			feedback.className = "form__feedback";

			if (!REST_URL || !REST_NONCE) {
				feedback.textContent = I18N.error || "Error";
				feedback.classList.add("form__feedback--err");
				return;
			}

			// Check custom selects marked required (their hidden input has data-required).
			var requiredSelects = form.querySelectorAll("[data-select-input][data-required]");
			for (var ri = 0; ri < requiredSelects.length; ri++) {
				if (!requiredSelects[ri].value) {
					feedback.textContent = I18N.required || "Please fill out all required fields.";
					feedback.classList.add("form__feedback--err");
					var sel = requiredSelects[ri].closest("[data-select]");
					var trig = sel && sel.querySelector("[data-select-trigger]");
					if (trig) trig.focus();
					return;
				}
			}

			var payload = {};
			new FormData(form).forEach(function (value, key) {
				if (typeof File !== "undefined" && value instanceof File) return;
				payload[key] = value;
			});

			submit.disabled = true;
			submit.setAttribute("aria-busy", "true");
			if (labelSpan && I18N.submitting) labelSpan.textContent = I18N.submitting;

			fetch(REST_URL + "contact", {
				method: "POST",
				headers: {
					"Content-Type": "application/json",
					"X-WP-Nonce": REST_NONCE
				},
				body: JSON.stringify(payload)
			}).then(function (res) {
				return res.json().then(function (data) { return { ok: res.ok, data: data }; });
			}).then(function (result) {
				if (result.ok) {
					feedback.textContent = I18N.sent || "Sent.";
					feedback.classList.add("form__feedback--ok");
					form.reset();
					// Reset every custom select inside the form too.
					form.querySelectorAll("[data-select]").forEach(function (sel) {
						if (typeof sel._reset === "function") sel._reset();
					});
				} else {
					feedback.textContent = (result.data && result.data.message) || I18N.error || "Error";
					feedback.classList.add("form__feedback--err");
				}
			}).catch(function () {
				feedback.textContent = I18N.error || "Error";
				feedback.classList.add("form__feedback--err");
			}).finally(function () {
				submit.disabled = false;
				submit.removeAttribute("aria-busy");
				if (labelSpan) labelSpan.textContent = defaultLabel;
			});
		});
	}

	function initAccordions() {
		var items = document.querySelectorAll(".accordion__item details");
		if (!items.length) return;
		var reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
		var DURATION = 250;
		var EASING = "cubic-bezier(0.4, 0, 0.2, 1)";

		items.forEach(function (details) {
			var summary = details.querySelector("summary");
			var body = details.querySelector(".accordion__body");
			if (!summary || !body) return;

			// Initial collapsed-when-closed sizing so the JS controls height from the start.
			if (!details.open) body.style.height = "0px";

			var animation = null;
			var pendingOpen = false;

			summary.addEventListener("click", function (e) {
				if (reduce) return; // let the browser handle it natively
				e.preventDefault();
				if (animation) animation.cancel();

				if (details.open && !pendingOpen) {
					// CLOSE
					var startClose = body.scrollHeight;
					body.style.height = startClose + "px";
					// next frame, transition to 0
					requestAnimationFrame(function () {
						animation = body.animate(
							[{ height: startClose + "px" }, { height: "0px" }],
							{ duration: DURATION, easing: EASING }
						);
						animation.onfinish = function () {
							details.open = false;
							body.style.height = "0px";
							animation = null;
						};
					});
				} else {
					// OPEN
					details.open = true;
					pendingOpen = true;
					body.style.height = "0px";
					requestAnimationFrame(function () {
						var target = body.scrollHeight;
						animation = body.animate(
							[{ height: "0px" }, { height: target + "px" }],
							{ duration: DURATION, easing: EASING }
						);
						animation.onfinish = function () {
							body.style.height = ""; // release to auto so later size changes (filter expand) work
							animation = null;
							pendingOpen = false;
						};
					});
				}
			});
		});
	}

	ready(function () {
		initHeaderScroll();
		initDropdowns();
		initMobileNav();
		initReveal();
		initStatsCounter();
		initFaqFilter();
		initNewsFilter();
		initSelects();
		initServicesWizard();
		initContactForm();
		initAccordions();
	});
})();
