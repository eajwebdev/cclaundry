import './bootstrap';
import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.min.css';
import Chart from 'chart.js/auto';
import Alpine from 'alpinejs';
import installRiderOverview from './rider-overview';
import installRiderRuns from './rider-runs';
import riderOutboxStore from './rider-outbox';
import Swal from 'sweetalert2';
import {
    Activity,
    ArrowLeft,
    ArrowRight,
    BedDouble,
    BedSingle,
    Bell,
    Bot,
    Building2,
    Calendar,
    CalendarCheck,
    Check,
    CheckCheck,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    ChevronUp,
    Clock,
    ClockAlert,
    CircleDollarSign,
    ClipboardList,
    CloudFog,
    CreditCard,
    Droplets,
    Eye,
    EyeOff,
    ExternalLink,
    FileBarChart2,
    FileText,
    Flame,
    FlaskConical,
    Footprints,
    GitBranch,
    Globe,
    Grid,
    HandCoins,
    Heart,
    House,
    IdCard,
    Info,
    Layers,
    Leaf,
    LayoutDashboard,
    LayoutGrid,
    ListOrdered,
    Loader2,
    LogIn,
    LockKeyhole,
    LogOut,
    Mail,
    MapPin,
    Menu,
    MessageCircle,
    MoreHorizontal,
    Moon,
    Package,
    PackageCheck,
    Phone,
    PackageOpen,
    Plus,
    Printer,
    QrCode,
    ReceiptText,
    RefreshCw,
    Quote,
    Save,
    Search,
    Settings,
    ShieldCheck,
    ShoppingBag,
    ShoppingCart,
    Shirt,
    Smartphone,
    Sparkles,
    Scale,
    Star,
    Store,
    Sun,
    Tag,
    Tags,
    Timer,
    TriangleAlert,
    Trash2,
    Truck,
    Navigation,
    Maximize,
    Route,
    LocateFixed,
    Flag,
    Coffee,
    HandHelping,
    StickyNote,
    Map,
    UserRound,
    Users,
    Wallet,
    WashingMachine,
    Wind,
    X,
    Zap,
} from 'lucide-static';

// Registered here rather than in the map bundle on purpose: the rider's run
// map has to render its address list even when the map bundle never arrives.
installRiderOverview();
installRiderRuns();

// The rider's queue of unsent taps. A store, not a component, because it has
// to survive navigating between the run list and a job.
Alpine.store('outbox', riderOutboxStore());

window.Alpine = Alpine;
window.Swal = Swal;
window.flatpickr = flatpickr;
window.Chart = Chart;
window.toast = Swal.mixin({
    toast: true,
    position: 'bottom-end',
    showConfirmButton: false,
    timer: 5200,
    timerProgressBar: true,
    showClass: {
        popup: 'swal2-show',
        backdrop: 'swal2-noanimation',
    },
    hideClass: {
        popup: 'swal2-hide',
        backdrop: 'swal2-noanimation',
    },
});

const icons = {
    activity: Activity,
    arrowLeft: ArrowLeft,
    'arrow-left': ArrowLeft,
    arrowRight: ArrowRight,
    'arrow-right': ArrowRight,
    'bed-double': BedDouble,
    bedDouble: BedDouble,
    'bed-single': BedSingle,
    bell: Bell,
    bot: Bot,
    building: Building2,
    calendar: CalendarCheck,
    'calendar-days': Calendar,
    check: Check,
    'check-check': CheckCheck,
    chevronDown: ChevronDown,
    'chevron-down': ChevronDown,
    'chevron-left': ChevronLeft,
    chevronRight: ChevronRight,
    'chevron-right': ChevronRight,
    chevronUp: ChevronUp,
    'chevron-up': ChevronUp,
    clock: ClockAlert,
    cycles: Activity,
    dashboard: LayoutDashboard,
    dollar: CircleDollarSign,
    droplets: Droplets,
    expense: CircleDollarSign,
    eye: Eye,
    eyeOff: EyeOff,
    externalLink: ExternalLink,
    'external-link': ExternalLink,
    flame: Flame,
    flaskConical: FlaskConical,
    'flask-conical': FlaskConical,
    footprints: Footprints,
    gitBranch: GitBranch,
    'git-branch': GitBranch,
    globe: Globe,
    heart: Heart,
    home: House,
    info: Info,
    layers: Layers,
    leaf: Leaf,
    grid: Grid,
    inventory: Package,
    fileText: FileText,
    'file-text': FileText,
    jobOrders: ClipboardList,
    laundry: WashingMachine,
    layoutGrid: LayoutGrid,
    'layout-grid': LayoutGrid,
    listOrdered: ListOrdered,
    'list-ordered': ListOrdered,
    loader: Loader2,
    lock: LockKeyhole,
    login: LogIn,
    logout: LogOut,
    mail: Mail,
    'map-pin': MapPin,
    mapPin: MapPin,
    menu: Menu,
    'message-circle': MessageCircle,
    moreHorizontal: MoreHorizontal,
    'more-horizontal': MoreHorizontal,
    moon: Moon,
    package: Package,
    packageCheck: PackageCheck,
    'package-check': PackageCheck,
    packageOpen: PackageOpen,
    'package-open': PackageOpen,
    payments: CreditCard,
    plus: Plus,
    phone: Phone,
    printer: Printer,
    quote: Quote,
    qr: QrCode,
    receipt: ReceiptText,
    receivables: HandCoins,
    refreshCw: RefreshCw,
    'refresh-cw': RefreshCw,
    reports: FileBarChart2,
    save: Save,
    search: Search,
    services: Tags,
    settings: Settings,
    shieldCheck: ShieldCheck,
    shoppingBag: ShoppingBag,
    'shopping-bag': ShoppingBag,
    shoppingCart: ShoppingCart,
    'shopping-cart': ShoppingCart,
    shirt: Shirt,
    smartphone: Smartphone,
    sms: Bell,
    steam: CloudFog,
    time: Clock,
    sparkles: Sparkles,
    scale: Scale,
    star: Star,
    store: Store,
    sun: Sun,
    tag: Tag,
    timer: Timer,
    alertTriangle: TriangleAlert,
    truck: Truck,
    navigation: Navigation,
    maximize: Maximize,
    route: Route,
    'locate-fixed': LocateFixed,
    flag: Flag,
    coffee: Coffee,
    'hand-helping': HandHelping,
    'sticky-note': StickyNote,
    map: Map,
    trash: Trash2,
    user: UserRound,
    users: Users,
    wallet: Wallet,
    wind: Wind,
    x: X,
    zap: Zap,
    branches: Building2,
    customers: Users,
    attendance: CalendarCheck,
    employees: IdCard,
    smsLogs: Bell,
};

window.renderLucideIcons = () => {
    document.querySelectorAll('[data-lucide]').forEach((node) => {
        const name = node.dataset.lucide;
        const svg = icons[name];

        if (!svg) {
            return;
        }

        const wrapper = document.createElement('span');
        wrapper.innerHTML = svg.trim();
        const icon = wrapper.firstElementChild;

        Array.from(node.attributes).forEach((attribute) => {
            if (attribute.name !== 'data-lucide') {
                icon.setAttribute(attribute.name, attribute.value);
            }
        });

        icon.setAttribute('class', node.getAttribute('class') || 'h-5 w-5');
        icon.setAttribute('aria-hidden', 'true');
        node.replaceWith(icon);
    });
};

const appThemeDefault = Boolean(window.appDarkModeDefault);
const appThemeDefaultKey = String(appThemeDefault);
const storedThemeDefaultKey = localStorage.getItem('themeDefault');
const storedTheme = localStorage.getItem('theme');

Alpine.store('theme', {
    dark: storedTheme && storedThemeDefaultKey === appThemeDefaultKey
        ? storedTheme === 'dark'
        : appThemeDefault,

    init() {
        this.apply();
    },

    toggle() {
        this.dark = !this.dark;
        localStorage.setItem('theme', this.dark ? 'dark' : 'light');
        localStorage.setItem('themeDefault', appThemeDefaultKey);
        this.apply();
    },

    apply() {
        localStorage.setItem('themeDefault', appThemeDefaultKey);
        document.documentElement.classList.toggle('dark', this.dark);
        document.documentElement.style.colorScheme = this.dark ? 'dark' : 'light';
    }
});

Alpine.start();

document.addEventListener('DOMContentLoaded', window.renderLucideIcons);

/**
 * A confirmation carried across a page change.
 *
 * The rider's collect and deliver actions post in the background and then
 * navigate themselves, so there is no server redirect to hang a flash message
 * on. The outgoing page leaves the message here and the next one says it.
 */
document.addEventListener('DOMContentLoaded', () => {
    let message = null;

    try {
        message = sessionStorage.getItem('rider-flash');
        if (message) sessionStorage.removeItem('rider-flash');
    } catch {
        // Storage unavailable; the action still happened, just quietly.
    }

    if (message) window.toast?.fire({ icon: 'success', title: message });
});
document.addEventListener('alpine:init', () => {
    queueMicrotask(window.renderLucideIcons);
});
