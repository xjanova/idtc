// Sources flattened with hardhat v2.28.6 https://hardhat.org

// SPDX-License-Identifier: MIT

// File @openzeppelin/contracts/utils/Context.sol@v5.6.1

// Original license: SPDX_License_Identifier: MIT
// OpenZeppelin Contracts (last updated v5.0.1) (utils/Context.sol)

pragma solidity ^0.8.20;

/**
 * @dev Provides information about the current execution context, including the
 * sender of the transaction and its data. While these are generally available
 * via msg.sender and msg.data, they should not be accessed in such a direct
 * manner, since when dealing with meta-transactions the account sending and
 * paying for execution may not be the actual sender (as far as an application
 * is concerned).
 *
 * This contract is only required for intermediate, library-like contracts.
 */
abstract contract Context {
    function _msgSender() internal view virtual returns (address) {
        return msg.sender;
    }

    function _msgData() internal view virtual returns (bytes calldata) {
        return msg.data;
    }

    function _contextSuffixLength() internal view virtual returns (uint256) {
        return 0;
    }
}


// File @openzeppelin/contracts/access/Ownable.sol@v5.6.1

// Original license: SPDX_License_Identifier: MIT
// OpenZeppelin Contracts (last updated v5.0.0) (access/Ownable.sol)

pragma solidity ^0.8.20;

/**
 * @dev Contract module which provides a basic access control mechanism, where
 * there is an account (an owner) that can be granted exclusive access to
 * specific functions.
 *
 * The initial owner is set to the address provided by the deployer. This can
 * later be changed with {transferOwnership}.
 *
 * This module is used through inheritance. It will make available the modifier
 * `onlyOwner`, which can be applied to your functions to restrict their use to
 * the owner.
 */
abstract contract Ownable is Context {
    address private _owner;

    /**
     * @dev The caller account is not authorized to perform an operation.
     */
    error OwnableUnauthorizedAccount(address account);

    /**
     * @dev The owner is not a valid owner account. (eg. `address(0)`)
     */
    error OwnableInvalidOwner(address owner);

    event OwnershipTransferred(address indexed previousOwner, address indexed newOwner);

    /**
     * @dev Initializes the contract setting the address provided by the deployer as the initial owner.
     */
    constructor(address initialOwner) {
        if (initialOwner == address(0)) {
            revert OwnableInvalidOwner(address(0));
        }
        _transferOwnership(initialOwner);
    }

    /**
     * @dev Throws if called by any account other than the owner.
     */
    modifier onlyOwner() {
        _checkOwner();
        _;
    }

    /**
     * @dev Returns the address of the current owner.
     */
    function owner() public view virtual returns (address) {
        return _owner;
    }

    /**
     * @dev Throws if the sender is not the owner.
     */
    function _checkOwner() internal view virtual {
        if (owner() != _msgSender()) {
            revert OwnableUnauthorizedAccount(_msgSender());
        }
    }

    /**
     * @dev Leaves the contract without owner. It will not be possible to call
     * `onlyOwner` functions. Can only be called by the current owner.
     *
     * NOTE: Renouncing ownership will leave the contract without an owner,
     * thereby disabling any functionality that is only available to the owner.
     */
    function renounceOwnership() public virtual onlyOwner {
        _transferOwnership(address(0));
    }

    /**
     * @dev Transfers ownership of the contract to a new account (`newOwner`).
     * Can only be called by the current owner.
     */
    function transferOwnership(address newOwner) public virtual onlyOwner {
        if (newOwner == address(0)) {
            revert OwnableInvalidOwner(address(0));
        }
        _transferOwnership(newOwner);
    }

    /**
     * @dev Transfers ownership of the contract to a new account (`newOwner`).
     * Internal function without access restriction.
     */
    function _transferOwnership(address newOwner) internal virtual {
        address oldOwner = _owner;
        _owner = newOwner;
        emit OwnershipTransferred(oldOwner, newOwner);
    }
}


// File @openzeppelin/contracts/token/ERC20/IERC20.sol@v5.6.1

// Original license: SPDX_License_Identifier: MIT
// OpenZeppelin Contracts (last updated v5.4.0) (token/ERC20/IERC20.sol)

pragma solidity >=0.4.16;

/**
 * @dev Interface of the ERC-20 standard as defined in the ERC.
 */
interface IERC20 {
    /**
     * @dev Emitted when `value` tokens are moved from one account (`from`) to
     * another (`to`).
     *
     * Note that `value` may be zero.
     */
    event Transfer(address indexed from, address indexed to, uint256 value);

    /**
     * @dev Emitted when the allowance of a `spender` for an `owner` is set by
     * a call to {approve}. `value` is the new allowance.
     */
    event Approval(address indexed owner, address indexed spender, uint256 value);

    /**
     * @dev Returns the value of tokens in existence.
     */
    function totalSupply() external view returns (uint256);

    /**
     * @dev Returns the value of tokens owned by `account`.
     */
    function balanceOf(address account) external view returns (uint256);

    /**
     * @dev Moves a `value` amount of tokens from the caller's account to `to`.
     *
     * Returns a boolean value indicating whether the operation succeeded.
     *
     * Emits a {Transfer} event.
     */
    function transfer(address to, uint256 value) external returns (bool);

    /**
     * @dev Returns the remaining number of tokens that `spender` will be
     * allowed to spend on behalf of `owner` through {transferFrom}. This is
     * zero by default.
     *
     * This value changes when {approve} or {transferFrom} are called.
     */
    function allowance(address owner, address spender) external view returns (uint256);

    /**
     * @dev Sets a `value` amount of tokens as the allowance of `spender` over the
     * caller's tokens.
     *
     * Returns a boolean value indicating whether the operation succeeded.
     *
     * IMPORTANT: Beware that changing an allowance with this method brings the risk
     * that someone may use both the old and the new allowance by unfortunate
     * transaction ordering. One possible solution to mitigate this race
     * condition is to first reduce the spender's allowance to 0 and set the
     * desired value afterwards:
     * https://github.com/ethereum/EIPs/issues/20#issuecomment-263524729
     *
     * Emits an {Approval} event.
     */
    function approve(address spender, uint256 value) external returns (bool);

    /**
     * @dev Moves a `value` amount of tokens from `from` to `to` using the
     * allowance mechanism. `value` is then deducted from the caller's
     * allowance.
     *
     * Returns a boolean value indicating whether the operation succeeded.
     *
     * Emits a {Transfer} event.
     */
    function transferFrom(address from, address to, uint256 value) external returns (bool);
}


// File @openzeppelin/contracts/utils/StorageSlot.sol@v5.6.1

// Original license: SPDX_License_Identifier: MIT
// OpenZeppelin Contracts (last updated v5.1.0) (utils/StorageSlot.sol)
// This file was procedurally generated from scripts/generate/templates/StorageSlot.js.

pragma solidity ^0.8.20;

/**
 * @dev Library for reading and writing primitive types to specific storage slots.
 *
 * Storage slots are often used to avoid storage conflict when dealing with upgradeable contracts.
 * This library helps with reading and writing to such slots without the need for inline assembly.
 *
 * The functions in this library return Slot structs that contain a `value` member that can be used to read or write.
 *
 * Example usage to set ERC-1967 implementation slot:
 * ```solidity
 * contract ERC1967 {
 *     // Define the slot. Alternatively, use the SlotDerivation library to derive the slot.
 *     bytes32 internal constant _IMPLEMENTATION_SLOT = 0x360894a13ba1a3210667c828492db98dca3e2076cc3735a920a3ca505d382bbc;
 *
 *     function _getImplementation() internal view returns (address) {
 *         return StorageSlot.getAddressSlot(_IMPLEMENTATION_SLOT).value;
 *     }
 *
 *     function _setImplementation(address newImplementation) internal {
 *         require(newImplementation.code.length > 0);
 *         StorageSlot.getAddressSlot(_IMPLEMENTATION_SLOT).value = newImplementation;
 *     }
 * }
 * ```
 *
 * TIP: Consider using this library along with {SlotDerivation}.
 */
library StorageSlot {
    struct AddressSlot {
        address value;
    }

    struct BooleanSlot {
        bool value;
    }

    struct Bytes32Slot {
        bytes32 value;
    }

    struct Uint256Slot {
        uint256 value;
    }

    struct Int256Slot {
        int256 value;
    }

    struct StringSlot {
        string value;
    }

    struct BytesSlot {
        bytes value;
    }

    /**
     * @dev Returns an `AddressSlot` with member `value` located at `slot`.
     */
    function getAddressSlot(bytes32 slot) internal pure returns (AddressSlot storage r) {
        assembly ("memory-safe") {
            r.slot := slot
        }
    }

    /**
     * @dev Returns a `BooleanSlot` with member `value` located at `slot`.
     */
    function getBooleanSlot(bytes32 slot) internal pure returns (BooleanSlot storage r) {
        assembly ("memory-safe") {
            r.slot := slot
        }
    }

    /**
     * @dev Returns a `Bytes32Slot` with member `value` located at `slot`.
     */
    function getBytes32Slot(bytes32 slot) internal pure returns (Bytes32Slot storage r) {
        assembly ("memory-safe") {
            r.slot := slot
        }
    }

    /**
     * @dev Returns a `Uint256Slot` with member `value` located at `slot`.
     */
    function getUint256Slot(bytes32 slot) internal pure returns (Uint256Slot storage r) {
        assembly ("memory-safe") {
            r.slot := slot
        }
    }

    /**
     * @dev Returns a `Int256Slot` with member `value` located at `slot`.
     */
    function getInt256Slot(bytes32 slot) internal pure returns (Int256Slot storage r) {
        assembly ("memory-safe") {
            r.slot := slot
        }
    }

    /**
     * @dev Returns a `StringSlot` with member `value` located at `slot`.
     */
    function getStringSlot(bytes32 slot) internal pure returns (StringSlot storage r) {
        assembly ("memory-safe") {
            r.slot := slot
        }
    }

    /**
     * @dev Returns an `StringSlot` representation of the string storage pointer `store`.
     */
    function getStringSlot(string storage store) internal pure returns (StringSlot storage r) {
        assembly ("memory-safe") {
            r.slot := store.slot
        }
    }

    /**
     * @dev Returns a `BytesSlot` with member `value` located at `slot`.
     */
    function getBytesSlot(bytes32 slot) internal pure returns (BytesSlot storage r) {
        assembly ("memory-safe") {
            r.slot := slot
        }
    }

    /**
     * @dev Returns an `BytesSlot` representation of the bytes storage pointer `store`.
     */
    function getBytesSlot(bytes storage store) internal pure returns (BytesSlot storage r) {
        assembly ("memory-safe") {
            r.slot := store.slot
        }
    }
}


// File @openzeppelin/contracts/utils/ReentrancyGuard.sol@v5.6.1

// Original license: SPDX_License_Identifier: MIT
// OpenZeppelin Contracts (last updated v5.5.0) (utils/ReentrancyGuard.sol)

pragma solidity ^0.8.20;

/**
 * @dev Contract module that helps prevent reentrant calls to a function.
 *
 * Inheriting from `ReentrancyGuard` will make the {nonReentrant} modifier
 * available, which can be applied to functions to make sure there are no nested
 * (reentrant) calls to them.
 *
 * Note that because there is a single `nonReentrant` guard, functions marked as
 * `nonReentrant` may not call one another. This can be worked around by making
 * those functions `private`, and then adding `external` `nonReentrant` entry
 * points to them.
 *
 * TIP: If EIP-1153 (transient storage) is available on the chain you're deploying at,
 * consider using {ReentrancyGuardTransient} instead.
 *
 * TIP: If you would like to learn more about reentrancy and alternative ways
 * to protect against it, check out our blog post
 * https://blog.openzeppelin.com/reentrancy-after-istanbul/[Reentrancy After Istanbul].
 *
 * IMPORTANT: Deprecated. This storage-based reentrancy guard will be removed and replaced
 * by the {ReentrancyGuardTransient} variant in v6.0.
 *
 * @custom:stateless
 */
abstract contract ReentrancyGuard {
    using StorageSlot for bytes32;

    // keccak256(abi.encode(uint256(keccak256("openzeppelin.storage.ReentrancyGuard")) - 1)) & ~bytes32(uint256(0xff))
    bytes32 private constant REENTRANCY_GUARD_STORAGE =
        0x9b779b17422d0df92223018b32b4d1fa46e071723d6817e2486d003becc55f00;

    // Booleans are more expensive than uint256 or any type that takes up a full
    // word because each write operation emits an extra SLOAD to first read the
    // slot's contents, replace the bits taken up by the boolean, and then write
    // back. This is the compiler's defense against contract upgrades and
    // pointer aliasing, and it cannot be disabled.

    // The values being non-zero value makes deployment a bit more expensive,
    // but in exchange the refund on every call to nonReentrant will be lower in
    // amount. Since refunds are capped to a percentage of the total
    // transaction's gas, it is best to keep them low in cases like this one, to
    // increase the likelihood of the full refund coming into effect.
    uint256 private constant NOT_ENTERED = 1;
    uint256 private constant ENTERED = 2;

    /**
     * @dev Unauthorized reentrant call.
     */
    error ReentrancyGuardReentrantCall();

    constructor() {
        _reentrancyGuardStorageSlot().getUint256Slot().value = NOT_ENTERED;
    }

    /**
     * @dev Prevents a contract from calling itself, directly or indirectly.
     * Calling a `nonReentrant` function from another `nonReentrant`
     * function is not supported. It is possible to prevent this from happening
     * by making the `nonReentrant` function external, and making it call a
     * `private` function that does the actual work.
     */
    modifier nonReentrant() {
        _nonReentrantBefore();
        _;
        _nonReentrantAfter();
    }

    /**
     * @dev A `view` only version of {nonReentrant}. Use to block view functions
     * from being called, preventing reading from inconsistent contract state.
     *
     * CAUTION: This is a "view" modifier and does not change the reentrancy
     * status. Use it only on view functions. For payable or non-payable functions,
     * use the standard {nonReentrant} modifier instead.
     */
    modifier nonReentrantView() {
        _nonReentrantBeforeView();
        _;
    }

    function _nonReentrantBeforeView() private view {
        if (_reentrancyGuardEntered()) {
            revert ReentrancyGuardReentrantCall();
        }
    }

    function _nonReentrantBefore() private {
        // On the first call to nonReentrant, _status will be NOT_ENTERED
        _nonReentrantBeforeView();

        // Any calls to nonReentrant after this point will fail
        _reentrancyGuardStorageSlot().getUint256Slot().value = ENTERED;
    }

    function _nonReentrantAfter() private {
        // By storing the original value once again, a refund is triggered (see
        // https://eips.ethereum.org/EIPS/eip-2200)
        _reentrancyGuardStorageSlot().getUint256Slot().value = NOT_ENTERED;
    }

    /**
     * @dev Returns true if the reentrancy guard is currently set to "entered", which indicates there is a
     * `nonReentrant` function in the call stack.
     */
    function _reentrancyGuardEntered() internal view returns (bool) {
        return _reentrancyGuardStorageSlot().getUint256Slot().value == ENTERED;
    }

    function _reentrancyGuardStorageSlot() internal pure virtual returns (bytes32) {
        return REENTRANCY_GUARD_STORAGE;
    }
}


// File contracts/IDTCPresale.sol

// Original license: SPDX_License_Identifier: MIT
pragma solidity ^0.8.24;



/**
 * @title IDTCPresale
 * @notice 3-round presale: Seed ($0.03), Private ($0.04), Public ($0.05).
 *         Buyers pay with POL (native Polygon token, formerly MATIC).
 *
 * Token allocation per round (matches whitepaper):
 *   Seed:    10,000,000 IDTC cap  (10% of supply)
 *   Private: 15,000,000 IDTC cap  (15% of supply)
 *   Public:  25,000,000 IDTC cap  (25% of supply)
 *   Total presale: 50,000,000 IDTC (50% of supply)
 *
 * Listing price: $0.07 on QuickSwap V3 (Polygon)
 *
 * Security:
 *   - ReentrancyGuard on buyTokens and withdraw
 *   - Checks-Effects-Interactions pattern
 *   - Per-round min/max enforced on-chain
 *   - Owner cannot rug: tokens transferred immediately to buyer on purchase
 *   - Solidity 0.8+ built-in overflow protection
 */
contract IDTCPresale is Ownable, ReentrancyGuard {

    // ─── Enums ────────────────────────────────────────────────────────────────

    enum Round { NONE, SEED, PRIVATE, PUBLIC }

    // ─── Round caps (matches whitepaper tokenomics) ───────────────────────────

    uint256 public constant SEED_CAP    = 10_000_000 * 1e18;  // 10M IDTC
    uint256 public constant PRIVATE_CAP = 15_000_000 * 1e18;  // 15M IDTC
    uint256 public constant PUBLIC_CAP  = 25_000_000 * 1e18;  // 25M IDTC

    // ─── USD price per token (in cents) ───────────────────────────────────────

    uint256 public constant SEED_PRICE_USD_CENTS    = 3;  // $0.03
    uint256 public constant PRIVATE_PRICE_USD_CENTS = 4;  // $0.04
    uint256 public constant PUBLIC_PRICE_USD_CENTS  = 5;  // $0.05

    // ─── Per-round min/max purchase in IDTC (18 decimals) ────────────────────

    uint256 public constant SEED_MIN    = 1_000   * 1e18;   // 1,000 IDTC minimum
    uint256 public constant SEED_MAX    = 500_000 * 1e18;   // 500,000 IDTC maximum

    uint256 public constant PRIVATE_MIN = 500     * 1e18;   // 500 IDTC minimum
    uint256 public constant PRIVATE_MAX = 250_000 * 1e18;   // 250,000 IDTC maximum

    uint256 public constant PUBLIC_MIN  = 100     * 1e18;   // 100 IDTC minimum
    uint256 public constant PUBLIC_MAX  = 100_000 * 1e18;   // 100,000 IDTC maximum

    // ─── State ────────────────────────────────────────────────────────────────

    IERC20 public immutable idtcToken;

    Round  public currentRound;
    bool   public roundActive;

    /**
     * @notice USD value of 1 POL, with 18 decimals.
     *         e.g. 1 POL = $0.25 → set to 0.25e18 (250000000000000000)
     *
     * IMPORTANT: This is the POL price in USD, NOT POL per USD.
     */
    uint256 public usdPerPol;

    // Tokens sold per round
    uint256 public seedSold;
    uint256 public privateSold;
    uint256 public publicSold;

    // Total IDTC purchased per address (all rounds combined)
    mapping(address => uint256) public tokensBought;

    // Per-address per-round totals (for max enforcement)
    mapping(address => uint256) public seedBought;
    mapping(address => uint256) public privateBought;
    mapping(address => uint256) public publicBought;

    // ─── Events ───────────────────────────────────────────────────────────────

    event RoundStarted(Round indexed round);
    event RoundStopped(Round indexed round);
    event TokensPurchased(
        address indexed buyer,
        Round indexed round,
        uint256 polPaid,
        uint256 tokenAmount
    );
    event PolRateUpdated(uint256 newUsdPerPol);
    event Withdrawn(address indexed owner, uint256 amount);

    // ─── Constructor ──────────────────────────────────────────────────────────

    /**
     * @param _idtcToken    Address of deployed IDTC ERC-20 token.
     * @param _initialOwner Owner/admin who controls rounds and withdrawals.
     * @param _usdPerPol    USD price of 1 POL, 18 decimals.
     *                      Example: 1 POL = $0.25 → pass 250000000000000000 (0.25e18)
     */
    constructor(
        address _idtcToken,
        address _initialOwner,
        uint256 _usdPerPol
    ) Ownable(_initialOwner) {
        require(_idtcToken != address(0), "Zero token address");
        require(_usdPerPol > 0, "Invalid POL rate");
        idtcToken = IERC20(_idtcToken);
        usdPerPol = _usdPerPol;
        currentRound = Round.NONE;
        roundActive  = false;
    }

    // ─── Owner controls ───────────────────────────────────────────────────────

    /// @notice Start a sale round. Only owner.
    function startRound(Round _round) external onlyOwner {
        require(_round != Round.NONE, "Invalid round");
        currentRound = _round;
        roundActive  = true;
        emit RoundStarted(_round);
    }

    /// @notice Stop the active round. Only owner.
    function stopRound() external onlyOwner {
        require(roundActive, "No active round");
        roundActive = false;
        emit RoundStopped(currentRound);
    }

    /**
     * @notice Update POL price in USD. Call when POL price changes.
     * @param _usdPerPol New USD price of 1 POL, 18 decimals.
     *                   e.g. POL = $0.30 → pass 300000000000000000
     */
    function setUsdPerPol(uint256 _usdPerPol) external onlyOwner {
        require(_usdPerPol > 0, "Invalid rate");
        usdPerPol = _usdPerPol;
        emit PolRateUpdated(_usdPerPol);
    }

    // ─── Public purchase ──────────────────────────────────────────────────────

    /**
     * @notice Buy IDTC by sending POL. Tokens transferred immediately.
     *
     * @dev Formula:
     *   USD value of POL sent = msg.value * usdPerPol / 1e18
     *   Token price in USD    = priceCents / 100
     *   Tokens out            = (msg.value * usdPerPol * 100) / (priceCents * 1e18)
     *
     *   Example: 1 POL at $0.25, Private round ($0.04):
     *     = (1e18 * 0.25e18 * 100) / (4 * 1e18) = 6.25e18 → 6.25 IDTC ✓
     */
    function buyTokens() external payable nonReentrant {
        require(roundActive,       "No active round");
        require(msg.value > 0,     "Send POL to buy");

        uint256 priceCents = _currentPriceCents();
        uint256 cap        = _currentCap();
        uint256 minAmt     = _currentMin();
        uint256 maxAmt     = _currentMax();

        // Calculate tokens out (rounds down — protects contract)
        uint256 tokensOut = (msg.value * usdPerPol * 100) / (priceCents * 1e18);
        require(tokensOut > 0, "Insufficient POL for min 1 token");

        // Enforce per-purchase min
        require(tokensOut >= minAmt, "Below minimum purchase");

        // Enforce per-address max across this round
        uint256 alreadyBought = _addressRoundBought(msg.sender);
        require(alreadyBought + tokensOut <= maxAmt, "Exceeds per-address maximum");

        // Enforce round hard cap
        uint256 sold = _currentSold();
        require(sold + tokensOut <= cap, "Exceeds round cap");

        // ── Effects (all state before external call) ─────────────────────────
        _addSold(tokensOut);
        _addAddressBought(msg.sender, tokensOut);
        tokensBought[msg.sender] += tokensOut;

        // ── Interactions ─────────────────────────────────────────────────────
        require(
            idtcToken.transferFrom(owner(), msg.sender, tokensOut),
            "Token transfer failed"
        );

        emit TokensPurchased(msg.sender, currentRound, msg.value, tokensOut);
    }

    /// @notice Withdraw POL raised to owner wallet.
    function withdraw() external onlyOwner nonReentrant {
        uint256 balance = address(this).balance;
        require(balance > 0, "Nothing to withdraw");
        emit Withdrawn(msg.sender, balance);
        (bool ok, ) = payable(owner()).call{value: balance}("");
        require(ok, "Withdraw failed");
    }

    // ─── Views ────────────────────────────────────────────────────────────────

    /// @notice How many IDTC tokens you get for a given POL amount in active round.
    function tokensForPol(uint256 polAmount) external view returns (uint256) {
        if (!roundActive || polAmount == 0) return 0;
        uint256 priceCents = _currentPriceCents();
        return (polAmount * usdPerPol * 100) / (priceCents * 1e18);
    }

    /// @notice Current round token price in POL wei per 1 IDTC.
    function currentPriceInPol() external view returns (uint256) {
        if (!roundActive) return 0;
        uint256 priceCents = _currentPriceCents();
        return (priceCents * 1e18) / (100 * usdPerPol);
    }

    /// @notice Tokens remaining in the current round.
    function remainingInRound() external view returns (uint256) {
        if (!roundActive) return 0;
        return _currentCap() - _currentSold();
    }

    // ─── Internal helpers ─────────────────────────────────────────────────────

    function _currentPriceCents() internal view returns (uint256) {
        if (currentRound == Round.SEED)    return SEED_PRICE_USD_CENTS;
        if (currentRound == Round.PRIVATE) return PRIVATE_PRICE_USD_CENTS;
        if (currentRound == Round.PUBLIC)  return PUBLIC_PRICE_USD_CENTS;
        revert("No active round");
    }

    function _currentCap() internal view returns (uint256) {
        if (currentRound == Round.SEED)    return SEED_CAP;
        if (currentRound == Round.PRIVATE) return PRIVATE_CAP;
        if (currentRound == Round.PUBLIC)  return PUBLIC_CAP;
        revert("No active round");
    }

    function _currentMin() internal view returns (uint256) {
        if (currentRound == Round.SEED)    return SEED_MIN;
        if (currentRound == Round.PRIVATE) return PRIVATE_MIN;
        if (currentRound == Round.PUBLIC)  return PUBLIC_MIN;
        revert("No active round");
    }

    function _currentMax() internal view returns (uint256) {
        if (currentRound == Round.SEED)    return SEED_MAX;
        if (currentRound == Round.PRIVATE) return PRIVATE_MAX;
        if (currentRound == Round.PUBLIC)  return PUBLIC_MAX;
        revert("No active round");
    }

    function _currentSold() internal view returns (uint256) {
        if (currentRound == Round.SEED)    return seedSold;
        if (currentRound == Round.PRIVATE) return privateSold;
        if (currentRound == Round.PUBLIC)  return publicSold;
        revert("No active round");
    }

    function _addSold(uint256 amount) internal {
        if (currentRound == Round.SEED)         seedSold    += amount;
        else if (currentRound == Round.PRIVATE) privateSold += amount;
        else if (currentRound == Round.PUBLIC)  publicSold  += amount;
    }

    function _addressRoundBought(address buyer) internal view returns (uint256) {
        if (currentRound == Round.SEED)    return seedBought[buyer];
        if (currentRound == Round.PRIVATE) return privateBought[buyer];
        if (currentRound == Round.PUBLIC)  return publicBought[buyer];
        revert("No active round");
    }

    function _addAddressBought(address buyer, uint256 amount) internal {
        if (currentRound == Round.SEED)         seedBought[buyer]    += amount;
        else if (currentRound == Round.PRIVATE) privateBought[buyer] += amount;
        else if (currentRound == Round.PUBLIC)  publicBought[buyer]  += amount;
    }

    /// @dev Reject direct POL transfers — must use buyTokens()
    receive() external payable {
        revert("Use buyTokens()");
    }
}
