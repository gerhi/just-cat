## =====================================================================
##  Scoring CAT responses in R with mirt (fixed item parameters)
##  and comparing the results with the PHP application
## =====================================================================
##
##  Input files (from the admin area):
##    items_file : Export → "Item parameters" of the test
##    long_file  : Export → "Long" (or any long file with a person column,
##                 item_id and scored responses 0/1 or 0…K)
##    php_file   : optional, Rescore → "Calculate and download CSV"
##
##  The script computes θ (EAP) and SE per person in two ways:
##    A) mirt::fscores() on a mirt model with the item parameters fixed
##    B) a short base-R EAP that does not depend on mirt
##  Both use exactly the settings of the application: N(0,1) prior,
##  901 quadrature points on [-4.5, 4.5], logistic metric (no D = 1.7).
##  It then compares A, B and the PHP results.
## =====================================================================

library(mirt)   # install.packages("mirt")

items_file <- "cat_my-test_items_2026-11-15.csv"
long_file  <- "cat_my-test_long_2026-11-15.csv"
php_file   <- NULL   # e.g. "cat_my-test_long_2026-11-15_scored.csv"

# Reads both file styles of the application (R/SPSS and German Excel)
read_any <- function(f) {
  first <- readLines(f, n = 1, encoding = "UTF-8", warn = FALSE)
  if (grepl(";", first, fixed = TRUE)) {
    read.csv2(f, fileEncoding = "UTF-8-BOM", stringsAsFactors = FALSE, check.names = FALSE)
  } else {
    read.csv(f, fileEncoding = "UTF-8-BOM", stringsAsFactors = FALSE, check.names = FALSE)
  }
}

# ---------------------------------------------------------------------
# 1. Item parameters
# ---------------------------------------------------------------------
items <- read_any(items_file)
items$item_id <- as.character(items$item_id)

# GRM thresholds are stored as "b1 | b2 | …"
thr <- lapply(as.character(items$thresholds), function(x) {
  if (is.na(x) || trimws(x) == "") numeric(0)
  else as.numeric(gsub(",", ".", strsplit(x, "|", fixed = TRUE)[[1]], fixed = TRUE))  # also German Excel style
})
is_grm <- items$model == "GRM"
is_3pl <- items$model == "3PL"
c_par  <- ifelse(is_3pl, as.numeric(items$c), 0)

# ---------------------------------------------------------------------
# 2. Responses: long → wide matrix (persons × items), NA = not administered
# ---------------------------------------------------------------------
long <- read_any(long_file)
person_col <- intersect(c("person_id", "session_id", "person"), names(long))[1]
resp_col   <- intersect(c("scored_response", "score", "response"), names(long))[1]
stopifnot(!is.na(person_col), !is.na(resp_col), "item_id" %in% names(long))

long <- long[!is.na(long[[resp_col]]) & long[[resp_col]] != "", ]
long$item_id <- as.character(long$item_id)
unknown <- setdiff(long$item_id, items$item_id)
if (length(unknown)) stop("Items not in the item parameter file: ", paste(unknown, collapse = ", "))

persons <- unique(as.character(long[[person_col]]))
resp <- matrix(NA_integer_, nrow = length(persons), ncol = nrow(items),
               dimnames = list(persons, items$item_id))
resp[cbind(match(as.character(long[[person_col]]), persons),
           match(long$item_id, items$item_id))] <- as.integer(long[[resp_col]])

# Scored responses must be 0/1 (dichotomous) or 0…K (GRM)
max_cat <- ifelse(is_grm, lengths(thr), 1)
stopifnot(all(is.na(resp) | (resp >= 0 & sweep(resp, 2, max_cat, "<=")), na.rm = TRUE))

# ---------------------------------------------------------------------
# A) mirt with fixed parameters
# ---------------------------------------------------------------------
# mirt's slope-intercept form:  a1 = a,  d = -a*b,  g = c,  graded: d_k = -a*b_k
itemtype <- ifelse(is_grm, "graded", ifelse(is_3pl, "3PL", "2PL"))  # 1PL = 2PL with a fixed

# A small dummy data set in which every category of every item occurs,
# only used to set up the model structure.
dummy <- sapply(max_cat, function(k) pmin(0:max(max_cat), k))
dummy <- dummy[rep(seq_len(nrow(dummy)), 5), , drop = FALSE]
colnames(dummy) <- items$item_id

sv <- mirt(dummy, 1, itemtype = itemtype, pars = "values", verbose = FALSE)
stopifnot(all(items$item_id %in% sv$item))
set_par <- function(item, name, value) {
  idx <- sv$item == item & sv$name == name
  stopifnot(sum(idx) == 1)
  sv$value[idx] <<- value
}
for (j in seq_len(nrow(items))) {
  id <- items$item_id[j]
  a  <- as.numeric(items$a[j])
  set_par(id, "a1", a)
  if (is_grm[j]) {
    for (k in seq_along(thr[[j]])) set_par(id, paste0("d", k), -a * thr[[j]][k])
  } else {
    set_par(id, "d", -a * as.numeric(items$b[j]))
    if (is_3pl[j]) set_par(id, "g", c_par[j])
  }
}
# TOL = NaN: evaluate the model at the given values, no estimation
mod <- mirt(dummy, 1, itemtype = itemtype, pars = sv, TOL = NaN, verbose = FALSE)

# Check that the parameters arrived as intended (IRT metric: a, b, g)
print(head(coef(mod, IRTpars = TRUE, simplify = TRUE)$items))

fs <- fscores(mod, method = "EAP", response.pattern = resp,
              quadpts = 901, theta_lim = c(-4.5, 4.5))

# ---------------------------------------------------------------------
# B) The same EAP in base R (independent of mirt)
# ---------------------------------------------------------------------
grid  <- seq(-4.5, 4.5, length.out = 901)
prior <- dnorm(grid)

# Category probabilities on the grid: matrix 901 × (number of categories)
cat_probs <- function(j) {
  a <- as.numeric(items$a[j])
  if (is_grm[j]) {
    cum <- cbind(1, sapply(thr[[j]], function(b) plogis(a * (grid - b))), 0)
    pmax(cum[, -ncol(cum), drop = FALSE] - cum[, -1, drop = FALSE], 1e-10)
  } else {
    p <- c_par[j] + (1 - c_par[j]) * plogis(a * (grid - as.numeric(items$b[j])))
    p <- pmin(pmax(p, 1e-10), 1 - 1e-10)
    cbind(1 - p, p)
  }
}
P <- lapply(seq_len(nrow(items)), cat_probs)

eap <- t(apply(resp, 1, function(x) {
  ll <- numeric(length(grid))
  for (j in which(!is.na(x))) ll <- ll + log(P[[j]][, x[j] + 1])
  w  <- exp(ll - max(ll)) * prior
  th <- sum(grid * w) / sum(w)
  c(theta = th, se = sqrt(sum((grid - th)^2 * w) / sum(w)))
}))

# ---------------------------------------------------------------------
# C) Compare
# ---------------------------------------------------------------------
res <- data.frame(person_id  = persons,
                  n_items    = rowSums(!is.na(resp)),
                  theta_mirt = fs[, "F1"],  se_mirt = fs[, "SE_F1"],
                  theta_base = eap[, "theta"], se_base = eap[, "se"],
                  stringsAsFactors = FALSE)

if (!is.null(php_file)) {
  php <- read_any(php_file)
  php <- data.frame(person_id = as.character(php$person_id),
                    theta_php = php$theta, se_php = php$se, stringsAsFactors = FALSE)
  res <- merge(res, php, by = "person_id", sort = FALSE)
}

maxdiff <- function(x, y) signif(max(abs(x - y), na.rm = TRUE), 3)
cat("\nLargest absolute differences\n")
cat("  theta  mirt vs base R:", maxdiff(res$theta_mirt, res$theta_base),
    "   SE:", maxdiff(res$se_mirt, res$se_base), "\n")
if (!is.null(php_file)) {
  cat("  theta  PHP  vs base R:", maxdiff(res$theta_php, res$theta_base),
      "   SE:", maxdiff(res$se_php, res$se_base), "\n")
  cat("  theta  PHP  vs mirt  :", maxdiff(res$theta_php, res$theta_mirt),
      "   SE:", maxdiff(res$se_php, res$se_mirt), "\n")
  cat("  (the PHP file has 6 decimals, so differences up to 5e-7 are rounding)\n")
  plot(res$theta_mirt, res$theta_php, xlab = "θ mirt", ylab = "θ PHP", asp = 1)
  abline(0, 1, lty = 2)
}

head(res)
# write.csv(res, "scores_compared.csv", row.names = FALSE)
